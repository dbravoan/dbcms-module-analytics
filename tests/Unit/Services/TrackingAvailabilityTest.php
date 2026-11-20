<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Http\Middleware\SecurityHeaders;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Services\Cms\CookieConsentService;
use DbCms\Modules\Analytics\Services\AnalyticsCookieDisclosure;
use DbCms\Modules\Analytics\Services\AnalyticsManagerService;
use DbCms\Modules\Analytics\Services\Ga4CspContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TrackingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public const TEST_MODULES = ['analytics'];

    public function test_cookie_categories_follow_the_canonical_enable_switch(): void
    {
        app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_measurement_id', 'G-ABC1234567');
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isModuleEnabled')->willReturn(false);
        $disclosure = new AnalyticsCookieDisclosure($modules);
        $service = new CookieConsentService([$disclosure]);
        self::assertSame(['necessary'], array_keys($service->getDisclosedCategories('es')));

        $enabled = $this->createMock(ModuleManager::class);
        $enabled->method('isModuleEnabled')->willReturn(true);
        $service = new CookieConsentService([new AnalyticsCookieDisclosure($enabled)]);
        self::assertArrayHasKey('analytics', $service->getDisclosedCategories('es'));
    }

    public function test_tracking_csp_is_empty_when_owner_disabled_or_unconfigured(): void
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isModuleEnabled')->willReturn(false);
        $analytics = $this->createMock(AnalyticsManagerService::class);
        $analytics->expects(self::never())->method('measurementId');
        self::assertSame([], (new Ga4CspContributor($modules, $analytics))->connectOrigins());

        $enabled = $this->createMock(ModuleManager::class);
        $enabled->method('isModuleEnabled')->willReturn(true);
        $unconfigured = $this->createMock(AnalyticsManagerService::class);
        $unconfigured->method('measurementId')->willReturn(null);
        self::assertSame([], (new Ga4CspContributor($enabled, $unconfigured))->connectOrigins());
    }

    public function test_configured_tracking_has_only_exact_collect_and_loader_origins(): void
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isModuleEnabled')->willReturn(true);
        $analytics = $this->createMock(AnalyticsManagerService::class);
        $analytics->method('measurementId')->willReturn('G-ABC1234567');
        $contributor = new Ga4CspContributor($modules, $analytics);
        self::assertSame([], $contributor->connectOrigins());

        $consent = $this->createMock(CookieConsentService::class);
        $consent->method('hasConsent')->willReturnCallback(static fn (string $category): bool => $category === 'analytics');
        app()->instance(CookieConsentService::class, $consent);

        $origins = $contributor->connectOrigins();
        self::assertContains('https://www.google-analytics.com', $origins);
        self::assertContains('https://region1.google-analytics.com', $origins);
        self::assertContains('https://www.googletagmanager.com', $origins);
        self::assertStringNotContainsString('*', implode(' ', $origins));
        self::assertNotContains('https://www.facebook.com', $origins);

        app()->instance(ModuleManager::class, $modules);
        app()->instance(AnalyticsManagerService::class, $analytics);
        $policy = (new \ReflectionMethod(SecurityHeaders::class, 'contentSecurityPolicy'))->invoke(app(SecurityHeaders::class), 'test-nonce');
        self::assertIsString($policy);
        self::assertStringContainsString("connect-src 'self' https://www.googletagmanager.com", $policy);
        self::assertStringNotContainsString('https://*', $policy);
    }
}
