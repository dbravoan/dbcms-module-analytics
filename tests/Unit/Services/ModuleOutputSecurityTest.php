<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use DbCms\Core\Captcha\Services\CaptchaService;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Modules\Analytics\Services\AnalyticsManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class ModuleOutputSecurityTest extends TestCase
{
    private ModuleManager $modules;

    use RefreshDatabase;

    public const TEST_MODULES = ['analytics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->modules = app(ModuleManager::class);
        app(Vite::class)->useCspNonce('audit-nonce');
    }

    public function test_correct_captcha_is_accepted_exactly_once_and_wrong_answers_do_not_consume_it(): void
    {
        $service = app(CaptchaService::class);
        $challenge = $service->generateChallenge();
        preg_match('/(\d+)\s*\+\s*(\d+)\s*=/', $challenge['svg'], $operands);
        if (! isset($operands[1], $operands[2])) {
            throw new \LogicException('The generated challenge must contain both operands.');
        }
        $answer = (string) ((int) $operands[1] + (int) $operands[2]);

        self::assertFalse($service->verify('incorrect', $challenge['token']));
        self::assertTrue($service->verify($answer, $challenge['token']));
        self::assertFalse($service->verify($answer, $challenge['token']));
    }

    public function test_expired_and_tampered_captcha_are_rejected(): void
    {
        $service = app(CaptchaService::class);
        $expired = Crypt::encryptString(json_encode(['answer' => '5', 'expires_at' => time() - 1], JSON_THROW_ON_ERROR));
        self::assertFalse($service->verify('5', $expired));
        self::assertFalse($service->verify('5', $expired.'modified'));
    }

    public function test_unconfigured_analytics_does_not_load_a_placeholder_account(): void
    {
        self::assertSame('', (new AnalyticsManagerService($this->modules))->renderGa4Script());
    }

    public function test_analytics_rejects_script_injection_and_keeps_a_valid_consent_gated_account(): void
    {
        $service = new AnalyticsManagerService($this->modules);
        self::assertSame('', $service->renderGa4Script("G-X';globalThis.__audit=1;//"));
        self::assertSame('', $service->renderGa4Script('G-ABC123</script><script>globalThis.__audit=1</script>'));
        $html = $service->renderGa4Script('G-ABC1234567');
        self::assertStringContainsString('G-ABC1234567', $html);
        self::assertStringContainsString('cookie-consent-updated', $html);
        self::assertStringContainsString('nonce="audit-nonce"', $html);
    }

    public function test_disabled_modules_never_emit_tracking_or_dispatch_remote_events(): void
    {
        CmsModule::query()->where('identifier', 'analytics')->update(['is_active' => false]);
        self::assertSame('', (new AnalyticsManagerService($this->modules))->renderGa4Script('G-ABC1234567'));
    }
}
