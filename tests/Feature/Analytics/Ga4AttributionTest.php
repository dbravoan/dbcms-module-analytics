<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Modules\Analytics\Contracts\CampaignAttributionGatewayInterface;
use DbCms\Modules\Analytics\Contracts\Ga4SettingsNormalizerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\AnalyticsComposition;

uses(AnalyticsComposition::class);

beforeEach(function (): void {
    config(['cms.installation_id' => 'ga4-installation-a']);
    Http::preventStrayRequests();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $this->publicKey = openssl_pkey_get_details($key)['key'];
    $this->credentials = json_encode(['type' => 'service_account', 'client_email' => 'analytics@test-project.iam.gserviceaccount.com', 'private_key' => $private, 'private_key_id' => 'test-key', 'token_uri' => 'https://attacker.invalid/token'], JSON_THROW_ON_ERROR);
    app(ModuleSettingsServiceInterface::class)->setMany('analytics', ['ga4_property_id' => '12345', 'ga4_service_account_json' => $this->credentials, 'ga4_conversion_events' => 'generate_lead,purchase']);
});

function ga4TestResponse(int $sessions = 12, string $events = '2.5'): array
{
    return ['metricHeaders' => [['name' => 'sessions'], ['name' => 'keyEvents']], 'rows' => [['metricValues' => [['value' => (string) $sessions], ['value' => $events]]]], 'rowCount' => 1, 'metadata' => ['subjectToThresholding' => true]];
}

test('Analytics owns campaign reporting without registering Marketing', function (): void {
    expect(app()->bound(CampaignAttributionGatewayInterface::class))->toBeTrue()
        ->and(app()->bound('DbCms\\Modules\\Marketing\\Contracts\\CampaignAttributionGatewayInterface'))->toBeFalse()
        ->and(CmsModule::query()->where('identifier', 'marketing')->exists())->toBeFalse();
});

test('GA4 reporting signs read only JWT and filters exact campaign and content with truthful metrics', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/v1beta/properties/12345:runReport' => Http::response(ga4TestResponse())]);
    $report = app(CampaignAttributionGatewayInterface::class)->report('campaña-exacta', 'piece-1', '2026-10-01', '2026-10-13');
    expect($report['status'])->toBe('available')->and($report['source'])->toBe('ga4')->and($report['sessions'])->toBe(12)->and($report['key_events'])->toBe(2.5)->and($report['limitations'])->toContain('subject_to_thresholding');
    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        [$header, $payload, $signature] = explode('.', $request['assertion']);
        $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
        $claims = json_decode($decode($payload), true);
        expect($claims['scope'])->toBe('https://www.googleapis.com/auth/analytics.readonly')->and($claims['aud'])->toBe('https://oauth2.googleapis.com/token');
        expect(openssl_verify($header.'.'.$payload, $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    });
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), ':runReport')) {
            return false;
        }
        $expressions = $request['dimensionFilter']['andGroup']['expressions'];
        expect($expressions[0]['filter'])->toBe(['fieldName' => 'sessionManualCampaignName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'campaña-exacta', 'caseSensitive' => true]])
            ->and($expressions[1]['filter']['fieldName'])->toBe('sessionManualAdContent');

        return $request->hasHeader('Authorization', 'Bearer fixture-token');
    });
});

test('cached OAuth tokens are reused but successful reports stay bound to installation and credentials', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::sequence()->push(ga4TestResponse())->push([], 503)->push([], 503)->push([], 503)]);
    $gateway = app(CampaignAttributionGatewayInterface::class);
    $initial = $gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    $stale = $gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    expect($stale['status'])->toBe('stale')->and($stale['sessions'])->toBe(12)->and($stale['fetched_at'])->toBe($initial['fetched_at']);
    app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_service_account_json', str_replace('test-key', 'new-key', $this->credentials));
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('unavailable');
    config(['cms.installation_id' => 'ga4-installation-b']);
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('unavailable');
    expect(collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), '/token')))->toHaveCount(3);
});

test('inactive or unconfigured Analytics does not contact Google or fabricate zero', function (): void {
    app(ModuleManager::class)->deactivate('analytics');
    $gateway = app(CampaignAttributionGatewayInterface::class);
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['sessions'])->toBeNull();
    app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_property_id', '');
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('unavailable');
    Http::assertNothingSent();
});

test('an active Analytics without report configuration does not request a token', function (string $missing): void {
    if ($missing === 'installation') {
        config(['cms.installation_id' => '']);
    } elseif ($missing === 'property') {
        app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_property_id', '');
    } else {
        $settings = Mockery::mock(ModuleSettingsServiceInterface::class);
        $settings->shouldReceive('get')->with('analytics', 'ga4_property_id', '')->andReturn('12345');
        $settings->shouldReceive('get')->with('analytics', 'ga4_service_account_json', '')->andReturn($missing === 'credentials' ? '' : '{invalid-json');
        app()->instance(ModuleSettingsServiceInterface::class, $settings);
    }

    $report = app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    expect($report['status'])->toBe('unavailable')->and($report['sessions'])->toBeNull();
    Http::assertNothingSent();
})->with(['installation', 'property', 'credentials', 'invalid credentials']);

test('retained report fallback expires after seven days', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::sequence()->push(ga4TestResponse())->push([], 503)]);
    $gateway = app(CampaignAttributionGatewayInterface::class);
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('available');
    $this->travel(8)->days();

    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('unavailable');
});

test('invalid report windows are rejected before any provider call', function (): void {
    $gateway = app(CampaignAttributionGatewayInterface::class);
    foreach ([['2026-02-30', '2026-03-01'], ['2026-10-13', '2026-10-01'], ['2025-01-01', '2026-10-13']] as [$from, $to]) {
        expect(fn () => $gateway->report('campaign', 'piece', $from, $to))->toThrow(ValidationException::class);
    }
    Http::assertNothingSent();
});

test('missing metric headers are unavailable but confirmed empty reports legitimately return zero', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::sequence()->push([])->push(['metricHeaders' => [['name' => 'sessions'], ['name' => 'keyEvents']], 'rowCount' => 0])]);
    $gateway = app(CampaignAttributionGatewayInterface::class);
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['sessions'])->toBeNull();
    $empty = $gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    expect($empty['status'])->toBe('available')->and($empty['sessions'])->toBe(0)->and($empty['key_events'])->toBe(0.0);
});

test('service account JSON is encrypted centrally and never echoed by the settings screen', function (): void {
    expect(CmsModule::where('identifier', 'analytics')->firstOrFail()->settings['ga4_service_account_json'])->not->toContain('BEGIN PRIVATE KEY');
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']));
    $this->actingAs($admin)->get(route('admin.modules.settings', ['identifier' => 'analytics']))->assertOk()->assertDontSee('BEGIN PRIVATE KEY')->assertDontSee('analytics@test-project.iam.gserviceaccount.com');
    expect(fn () => app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_property_id', '../other'))->toThrow(InvalidArgumentException::class);
});

test('a withdrawn legal processing policy blocks credentials cached reports and remote requests', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::response(ga4TestResponse())]);
    $gateway = app(CampaignAttributionGatewayInterface::class);
    expect($gateway->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('available');
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', [], 'legal', 'json');
    $settings = Mockery::mock(ModuleSettingsServiceInterface::class);
    $settings->shouldNotReceive('get');
    app()->instance(ModuleSettingsServiceInterface::class, $settings);
    $normalizer = Mockery::mock(Ga4SettingsNormalizerInterface::class);
    $normalizer->shouldNotReceive('credentials');
    app()->instance(Ga4SettingsNormalizerInterface::class, $normalizer);
    Cache::partialMock()->shouldNotReceive('get')->withArgs(fn (string $key): bool => str_starts_with($key, 'ga4:'));
    Http::fake();

    $report = app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    expect($report['status'])->toBe('unavailable')->and($report['sessions'])->toBeNull()->and($report['fetched_at'])->toBeNull();
    Http::assertNothingSent();
});

test('the legal gate accepts reviewed analytics processing with manual retention review', function (): void {
    $processing = app(GeneralSettingsServiceInterface::class)->get('legal.processing');
    foreach ($processing as &$entry) {
        if ($entry['owner'] === 'analytics') {
            $entry['retention_days'] = null;
            $entry['manual_review'] = true;
        }
    }
    unset($entry);
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', $processing, 'legal', 'json');
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::response(ga4TestResponse())]);

    expect(app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('available');
});

test('cache outages do not discard fresh metrics or escape the unavailable result', function (bool $providerAvailable): void {
    Cache::partialMock()->shouldReceive('get')->withArgs(fn (string $key): bool => str_starts_with($key, 'ga4:'))->andThrow(new RuntimeException('Cache offline'));
    Cache::partialMock()->shouldReceive('put')->withArgs(fn (string $key): bool => str_starts_with($key, 'ga4:'))->andThrow(new RuntimeException('Cache offline'));
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::response($providerAvailable ? ga4TestResponse() : [], $providerAvailable ? 200 : 503)]);

    $report = app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    expect($report['status'])->toBe($providerAvailable ? 'available' : 'unavailable')->and($report['sessions'])->toBe($providerAvailable ? 12 : null);
})->with([true, false]);

test('token expiry and corrupt encrypted tokens trigger fresh authorization', function (string $tokenState): void {
    $identity = hash('sha256', 'ga4-installation-a|12345|'.hash('sha256', $this->credentials));
    Cache::put('ga4:token:'.$identity, ['token' => $tokenState === 'corrupt' ? 'broken-ciphertext' : Crypt::encryptString('expired-token'), 'expires_at' => now()->getTimestamp() + ($tokenState === 'corrupt' ? 3600 : 60)], 3600);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::response(ga4TestResponse())]);

    expect(app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('available');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/token'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer renewed-token'));
})->with(['expired', 'corrupt']);

test('malformed OAuth responses never reach the report endpoint', function (array $token): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response($token), 'analyticsdata.googleapis.com/*' => Http::response(ga4TestResponse())]);
    expect(app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13')['status'])->toBe('unavailable');
    Http::assertSentCount(1);
})->with([
    'empty token' => [['access_token' => '', 'expires_in' => 3600, 'token_type' => 'Bearer']],
    'whitespace token' => [['access_token' => '   ', 'expires_in' => 3600, 'token_type' => 'Bearer']],
    'header injection' => [['access_token' => "token\r\nOther: injected", 'expires_in' => 3600, 'token_type' => 'Bearer']],
    'expired token' => [['access_token' => 'fixture-token', 'expires_in' => 60, 'token_type' => 'Bearer']],
    'invalid expiry' => [['access_token' => 'fixture-token', 'expires_in' => '3600.5', 'token_type' => 'Bearer']],
    'invalid type' => [['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Basic']],
]);

test('the maximum window includes 366 calendar days and rejects a 367th day', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::response(ga4TestResponse())]);
    $gateway = app(CampaignAttributionGatewayInterface::class);
    expect($gateway->report('campaign', 'piece', '2024-01-01', '2024-12-31')['status'])->toBe('available');
    expect(fn () => $gateway->report('campaign', 'piece', '2024-01-01', '2025-01-01'))->toThrow(ValidationException::class);
    Http::assertSentCount(2);
});

test('invalid provider metrics never become fabricated measurements', function (array $payload): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), 'analyticsdata.googleapis.com/*' => Http::response($payload)]);
    $report = app(CampaignAttributionGatewayInterface::class)->report('campaign', 'piece', '2026-10-01', '2026-10-13');
    expect($report['status'])->toBe('unavailable')->and($report['sessions'])->toBeNull()->and($report['key_events'])->toBeNull();
})->with([
    'fractional sessions' => [array_replace_recursive(ga4TestResponse(), ['rows' => [['metricValues' => [['value' => '1.5']]]]])],
    'negative events' => [ga4TestResponse(12, '-1')],
    'nonfinite events' => [ga4TestResponse(12, '1e999')],
    'invalid rows' => [array_replace(ga4TestResponse(), ['rows' => 'invalid'])],
    'multiple rows' => [array_replace(ga4TestResponse(), ['rowCount' => 2])],
    'invalid empty row count' => [['metricHeaders' => [['name' => 'sessions'], ['name' => 'keyEvents']], 'rows' => [], 'rowCount' => 'invalid']],
    'missing metric value' => [array_replace(ga4TestResponse(), ['rows' => [['metricValues' => [['value' => '12']]]]])],
]);
