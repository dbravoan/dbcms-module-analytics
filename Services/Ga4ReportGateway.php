<?php

declare(strict_types=1);

namespace DbCms\Modules\Analytics\Services;

use DateTimeImmutable;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Services\Privacy\LegalPageService;
use DbCms\Modules\Analytics\Contracts\CampaignAttributionGatewayInterface;
use DbCms\Modules\Analytics\Contracts\Ga4SettingsNormalizerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Optional reporting authority. GA4 collection and social counters stay separate. */
final class Ga4ReportGateway implements CampaignAttributionGatewayInterface
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const READ_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function __construct(private readonly ModuleManager $modules, private readonly ModuleSettingsServiceInterface $settings, private readonly Ga4SettingsNormalizerInterface $normalizer, private readonly LegalPageService $legal) {}

    /** @return array<string,mixed> */
    public function report(string $campaign, string $content, string $dateFrom, string $dateTo): array
    {
        $this->validateWindow($campaign, $content, $dateFrom, $dateTo);
        $unavailable = ['status' => 'unavailable', 'source' => 'ga4', 'sessions' => null, 'key_events' => null, 'fetched_at' => null, 'limitations' => ['analytics_consent_and_provider_attribution_limits'], 'provenance' => ['campaign' => $campaign, 'content' => $content, 'date_from' => $dateFrom, 'date_to' => $dateTo]];
        if (! $this->modules->isModuleEnabled('analytics') || ! $this->legal->canCollect('analytics', true)) {
            return $unavailable;
        }
        $property = trim((string) $this->settings->get('analytics', 'ga4_property_id', ''));
        $json = (string) $this->settings->get('analytics', 'ga4_service_account_json', '');
        $installation = (string) config('cms.installation_id');
        if (! preg_match('/\A[0-9]{1,20}\z/', $property) || trim($json) === '' || $installation === '') {
            return $unavailable;
        }
        // Both token and retained report identities change when credentials change.
        $identity = hash('sha256', $installation.'|'.$property.'|'.hash('sha256', $json));
        $reportKey = 'ga4:report:'.$identity.':'.hash('sha256', json_encode([$campaign, $content, $dateFrom, $dateTo], JSON_THROW_ON_ERROR));
        try {
            $credentials = $this->normalizer->credentials($json);
            $token = $this->accessToken($identity, $credentials);
            $response = Http::withToken($token)->acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()
                ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$property.':runReport', [
                    'dateRanges' => [['startDate' => $dateFrom, 'endDate' => $dateTo]],
                    'metrics' => [['name' => 'sessions'], ['name' => 'keyEvents']],
                    'dimensionFilter' => ['andGroup' => ['expressions' => [
                        $this->exactFilter('sessionManualCampaignName', $campaign),
                        $this->exactFilter('sessionManualAdContent', $content),
                    ]]],
                    'limit' => '1',
                ]);
            if (! $response->successful()) {
                throw new RuntimeException('GA4 report unavailable.');
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('GA4 report invalid.');
            }
            $metrics = $this->metrics($payload);
            $result = array_replace($unavailable, $metrics, ['status' => 'available', 'fetched_at' => now()->toIso8601String()]);
            $result['provenance']['property_id'] = $property;
            if (($payload['metadata']['subjectToThresholding'] ?? false) === true) {
                $result['limitations'][] = 'subject_to_thresholding';
            }
            if (($payload['metadata']['dataLossFromOtherRow'] ?? false) === true) {
                $result['limitations'][] = 'data_loss_from_other_row';
            }
            try {
                Cache::put($reportKey, $result, now()->addDays(7));
            } catch (\Throwable) {
                // Retention is best effort; a fresh provider response remains valid.
            }

            return $result;
        } catch (\Throwable) {
            try {
                $previous = Cache::get($reportKey);
            } catch (\Throwable) {
                return $unavailable;
            }

            return is_array($previous) ? array_replace($previous, ['status' => 'stale']) : $unavailable;
        }
    }

    /** @param array{email:string,key:string,key_id:?string} $credentials */
    private function accessToken(string $identity, array $credentials): string
    {
        $cacheKey = 'ga4:token:'.$identity;
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && is_string($cached['token'] ?? null) && is_int($cached['expires_at'] ?? null) && $cached['expires_at'] > now()->getTimestamp() + 60) {
                $token = Crypt::decryptString($cached['token']);
                if ($this->validToken($token)) {
                    return $token;
                }
            }
        } catch (\Throwable) {
            // Missing cache or obsolete ciphertext must not prevent authorization.
        }
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($credentials['key_id'] !== null) {
            $header['kid'] = $credentials['key_id'];
        }
        $issuedAt = now()->getTimestamp();
        $claims = ['iss' => $credentials['email'], 'scope' => self::READ_SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $issuedAt, 'exp' => $issuedAt + 3600];
        $unsigned = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)).'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        if (! openssl_sign($unsigned, $signature, $credentials['key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('GA4 authorization unavailable.');
        }
        $response = Http::asForm()->acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()->post(self::TOKEN_URL, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$this->base64Url($signature)]);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! is_string($data['access_token'] ?? null) || ! $this->validToken($data['access_token'])
            || ($data['token_type'] ?? '') !== 'Bearer' || (! is_int($data['expires_in'] ?? null) && (! is_string($data['expires_in'] ?? null) || ! ctype_digit($data['expires_in']))) || (int) $data['expires_in'] <= 60) {
            throw new RuntimeException('GA4 authorization unavailable.');
        }
        $expires = min(3600, (int) $data['expires_in']);
        try {
            Cache::put($cacheKey, ['token' => Crypt::encryptString($data['access_token']), 'expires_at' => now()->getTimestamp() + $expires], $expires - 60);
        } catch (\Throwable) {
            // The token remains usable for this request without persistent caching.
        }

        return $data['access_token'];
    }

    private function validToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/', $token) === 1;
    }

    /** @return array<string,mixed> */
    private function exactFilter(string $dimension, string $value): array
    {
        return ['filter' => ['fieldName' => $dimension, 'stringFilter' => ['matchType' => 'EXACT', 'value' => $value, 'caseSensitive' => true]]];
    }

    /** @param array<string,mixed> $payload
     * @return array{sessions:int,key_events:float}
     */
    private function metrics(array $payload): array
    {
        $headers = $payload['metricHeaders'] ?? null;
        if (! is_array($headers) || ! array_is_list($headers) || count($headers) !== 2 || ! is_array($headers[0]) || ! is_array($headers[1])
            || ($headers[0]['name'] ?? null) !== 'sessions' || ($headers[1]['name'] ?? null) !== 'keyEvents') {
            throw new RuntimeException('GA4 metrics unavailable.');
        }
        $rows = $payload['rows'] ?? [];
        $rowCount = $payload['rowCount'] ?? ($rows === [] ? 0 : 1);
        if (! is_int($rowCount) || $rowCount < 0) {
            throw new RuntimeException('GA4 aggregation invalid.');
        }
        if ($rows === [] && $rowCount === 0) {
            return ['sessions' => 0, 'key_events' => 0.0];
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0]) || $rowCount !== 1) {
            throw new RuntimeException('GA4 aggregation invalid.');
        }
        $values = $rows[0]['metricValues'] ?? [];
        if (! is_array($values) || ! array_is_list($values) || count($values) !== 2 || ! is_array($values[0]) || ! is_array($values[1])) {
            throw new RuntimeException('GA4 metrics invalid.');
        }
        $sessions = $values[0]['value'] ?? null;
        $events = $values[1]['value'] ?? null;
        if (! is_string($sessions) || ! ctype_digit($sessions) || strlen($sessions) > 18
            || ! is_string($events) || ! is_numeric($events) || ! is_finite((float) $events) || (float) $events < 0) {
            throw new RuntimeException('GA4 metrics invalid.');
        }

        return ['sessions' => (int) $sessions, 'key_events' => (float) $events];
    }

    private function validateWindow(string $campaign, string $content, string $dateFrom, string $dateTo): void
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
        if ($from === false || $to === false || $from->format('Y-m-d') !== $dateFrom || $to->format('Y-m-d') !== $dateTo
            || $from > $to || $from->diff($to)->days + 1 > 366 || $campaign === '' || $content === '' || strlen($campaign) > 255 || strlen($content) > 255) {
            throw ValidationException::withMessages(['date_range' => 'Indica una campaña, una pieza y un intervalo válido de hasta 366 días.']);
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
