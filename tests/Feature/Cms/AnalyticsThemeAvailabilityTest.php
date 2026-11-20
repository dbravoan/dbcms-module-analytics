<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Services\Cms\CookieConsentService;
use DbCms\Core\Services\Cms\ThemeManagerService;
use DbCms\Modules\Analytics\Services\AnalyticsManagerService;
use DbCms\Modules\Analytics\Services\Ga4CspContributor;
use Illuminate\Http\Request;
use Tests\Support\AnalyticsComposition;
use Tests\Support\CmsFixtures;

uses(AnalyticsComposition::class);

test('every distributed theme emits configured analytics only while its owner is enabled', function (string $theme): void {
    $this->withoutVite();
    CmsFixtures::localization(['es'], ['es']);
    CmsFixtures::home(['es'], ['es']);
    app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_measurement_id', 'G-CONTRACT123');
    app(ThemeManagerService::class)->setActiveTheme($theme);
    expect(app(ThemeManagerService::class)->getActiveTheme())->toBe($theme);
    config(['cms.page_cache.enabled' => false]);
    $this->get('/es/es')->assertOk()->assertSee('G-CONTRACT123')->assertSee('cookie-consent-updated');
    CmsModule::where('identifier', 'analytics')->update(['is_active' => false]);
    $this->get('/es/es')->assertOk()->assertDontSee('G-CONTRACT123');
})->with(['default-clean', 'db-cyber', 'db-brutalist', 'db-minimal', 'db-sunset', 'db-editorial', 'db-enterprise']);

test('editorial previews emit no analytics script or Google CSP origins despite accepted consent', function (): void {
    $this->withoutVite();
    CmsFixtures::localization(['es'], ['es']);
    app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_measurement_id', 'G-PREVIEW123');
    $consent = app(CookieConsentService::class)->record(['analytics' => true]);
    $request = Request::create('/editorial-preview', 'GET', [], ['cms_cookie_consent' => $consent['id']]);
    app()->instance('request', $request);
    expect(app(AnalyticsManagerService::class)->renderGa4Script())->toContain('G-PREVIEW123');
    expect(app(Ga4CspContributor::class)->connectOrigins())->not->toBeEmpty();

    $request->attributes->set('cms.editorial_preview', true);
    expect(app(AnalyticsManagerService::class)->renderGa4Script())->toBe('')
        ->and(app(Ga4CspContributor::class)->connectOrigins())->toBe([]);
});
