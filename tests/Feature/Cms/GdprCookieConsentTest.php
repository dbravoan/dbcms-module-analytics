<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Services\Cms\CookieConsentService;
use DbCms\Core\Services\Cms\ThemeManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CmsFixtures;
use Tests\Support\ConsentComposition;

uses(RefreshDatabase::class, ConsentComposition::class);

beforeEach(function (): void {
    app(ModuleSettingsServiceInterface::class)->set('analytics', 'ga4_measurement_id', 'G-TEST12345');
    CmsFixtures::localization(['es', 'en', 'fr'], ['es', 'us', 'eu']);
    CmsFixtures::home(['es', 'en', 'fr'], ['es', 'us', 'eu']);
});

test('cookie consent service discovers active module categories dynamically', function (): void {
    $service = app(CookieConsentService::class);

    $categories = $service->getDisclosedCategories('es');

    // Necessary category is always present
    expect($categories)->toHaveKey('necessary');
    expect($categories['necessary']['required'])->toBeTrue();

    // The cart uses the already disclosed necessary session.
    expect($categories)->not->toHaveKey('functional');

    // Analytics module is active -> analytics category present
    expect($categories)->toHaveKey('analytics');
    expect($categories['analytics']['required'])->toBeFalse();
    expect($categories['analytics']['cookies'])->toBeArray();

    // If analytics module is deactivated, category disappears
    CmsModule::where('identifier', 'analytics')->update(['is_active' => false]);
    $categoriesWithoutAnalytics = $service->getDisclosedCategories('es');
    expect($categoriesWithoutAnalytics)->not->toHaveKey('analytics');
});

test('cookie consent service correctly checks consent for categories', function (): void {
    $service = app(CookieConsentService::class);

    // Necessary is always allowed
    expect($service->hasConsent('necessary'))->toBeTrue();

    $receipt = $service->record(['analytics' => true]);
    $request = Request::create('/es/es/', 'GET', [], ['cms_cookie_consent' => $receipt['id']]);

    expect($service->hasConsent('necessary', $request))->toBeTrue();
    expect($service->hasConsent('analytics', $request))->toBeTrue();
    expect($service->hasConsent('functional', $request))->toBeFalse();
    expect($service->hasConsent('marketing', $request))->toBeFalse();

    $prefs = $service->getConsentPreferences($request);
    expect($prefs['necessary'])->toBeTrue();
    expect($prefs['analytics'])->toBeTrue();
    expect($prefs)->not->toHaveKey('functional');
});

test('frontoffice layout includes cookie consent banner and modal component', function (): void {
    $response = $this->get('/es/es/');
    $response->assertOk();

    // Check presence of cookie consent banner and GDPR preference modal
    $response->assertSee('Privacidad & Preferencias de Cookies');
    $response->assertSee('Aceptar Todas');
    $response->assertSee('Solo Necesarias');
    $response->assertSee('Personalizar');
    $response->assertSee('Centro de Preferencias de Cookies RGPD');
    $response->assertSee('XSRF-TOKEN');
});

test('frontoffice layout translates cookie banner in French and English', function (): void {
    // In French
    $resFr = $this->get('/eu/fr');
    $resFr->assertOk();
    $resFr->assertSee('Confidentialité & préférences de cookies');
    $resFr->assertSee('Tout accepter');
    $resFr->assertSee('Nécessaires uniquement');

    // In English
    $resEn = $this->get('/us/en/');
    $resEn->assertOk();
    $resEn->assertSee('Privacy & Cookie Preferences');
    $resEn->assertSee('Accept All');
    $resEn->assertSee('Necessary Only');
});

test('cached public markup delegates the returning visitor decision to the private endpoint', function (): void {
    $receipt = app(CookieConsentService::class)->record(['analytics' => true]);
    $this->withUnencryptedCookie('cms_cookie_consent', $receipt['id'])->get('/es/es/')->assertOk()->assertSee('data-consent-endpoint', false);
    $this->withCredentials()->getJson('/privacy/consent')->assertOk()->assertJsonPath('configured', true)->assertJsonPath('preferences.analytics', true);
});

test('db-cyber footer translations render localized headings, links, and cookie triggers across locales', function (): void {
    app(ThemeManagerService::class)->setActiveTheme('db-cyber');
    // French
    $resFr = $this->get('/eu/fr');
    $resFr->assertOk();
    $resFr->assertSee('Navigation rapide');
    $resFr->assertSee('Mentions légales');
    $resFr->assertSee('Paramètres des cookies');
    $resFr->assertSee('Tous droits réservés.');
    $resFr->assertSee('Marché actif :');

    // English
    $resEn = $this->get('/us/en/');
    $resEn->assertOk();
    $resEn->assertSee('Quick Navigation');
    $resEn->assertSee('Legal & Transparency');
    $resEn->assertSee('Cookie Settings');
    $resEn->assertSee('All rights reserved.');
    $resEn->assertSee('Active Market:');

    // Spanish
    $resEs = $this->get('/es/es/');
    $resEs->assertOk();
    $resEs->assertSee('Navegación Rápida');
    $resEs->assertSee('Legal & Transparencia');
    $resEs->assertSee('Configuración de Cookies');
    $resEs->assertSee('Todos los derechos reservados.');
    $resEs->assertSee('Mercado Activo:');
});
