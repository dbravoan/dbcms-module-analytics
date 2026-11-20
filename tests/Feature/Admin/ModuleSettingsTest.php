<?php

declare(strict_types=1);

use DbCms\Core\Database\Seeders\CmsFoundationSeeder;
use DbCms\Core\Forms\Models\CustomForm;
use DbCms\Core\Forms\Models\CustomFormTranslation;
use DbCms\Core\Forms\Models\FormField;
use DbCms\Core\Forms\Models\FormFieldTranslation;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Permission;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\ModuleSettingsComposition;

uses(RefreshDatabase::class, ModuleSettingsComposition::class);

beforeEach(function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $this->serviceCredentials = json_encode([
        'type' => 'service_account', 'client_email' => 'settings@test-project.iam.gserviceaccount.com',
        'private_key' => $privateKey,
    ], JSON_THROW_ON_ERROR);
    $this->seed(CmsFoundationSeeder::class);
    $this->manager = app(ModuleManager::class);
    $this->manager->discoverModules();

    $this->admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@db-cms.io',
        'password' => bcrypt('secret123'),
    ]);
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->admin->roles()->sync([$adminRole->id]);

    $this->viewer = User::create([
        'name' => 'Viewer User',
        'email' => 'viewer@db-cms.io',
        'password' => bcrypt('secret123'),
    ]);
    $viewerRole = Role::firstOrCreate(['slug' => 'viewer'], ['name' => 'Viewer']);
    $access = Permission::firstOrCreate(['slug' => 'access:admin'], ['name' => 'Administrative access']);
    $viewerRole->permissions()->syncWithoutDetaching([$access->id]);
    $this->viewer->roles()->sync([$viewerRole->id]);
});

test('unauthenticated users are redirected to admin login', function (): void {
    $response = $this->get(route('admin.modules.settings', ['identifier' => 'analytics']));
    $response->assertRedirect(route('admin.login'));
});

test('non-admin users without manage:modules permission are forbidden (403)', function (): void {
    $response = $this->actingAs($this->viewer, 'web')
        ->get(route('admin.modules.settings', ['identifier' => 'analytics']));

    $response->assertStatus(403);
});

test('admin can view module settings page with dynamic schema fields rendered', function (): void {
    $response = $this->actingAs($this->admin, 'web')
        ->get(route('admin.modules.settings', ['identifier' => 'analytics']));

    $response->assertStatus(200);
    $response->assertSee('Ajustes del Módulo:');
    $response->assertSee('GA4 Measurement ID');
    $response->assertDontSee('name="gtm_id"', false);
    $response->assertDontSee('name="anonymize_ip"', false);
    $response->assertDontSee('name="enable_cookie_consent"', false);
    $response->assertSee('Anonimizar IPs (RGPD)');
});

test('admin can update module settings and secrets are encrypted in database', function (): void {
    $analyticsModule = CmsModule::where('identifier', 'analytics')->firstOrFail();

    $response = $this->actingAs($this->admin, 'web')
        ->put(route('admin.modules.settings.update', ['identifier' => 'analytics']), [
            'ga4_measurement_id' => 'G-ABC1234567',
            'ga4_service_account_json' => $this->serviceCredentials,
            'ga4_conversion_events' => 'TEST_LIVE_01',
        ]);

    $response->assertRedirect(route('admin.modules.settings', ['identifier' => 'analytics']));
    $response->assertSessionHas('success');

    $analyticsModule->refresh();
    expect($analyticsModule->settings)->toBeArray();
    expect($analyticsModule->settings['ga4_measurement_id'])->toBe('G-ABC1234567');
    expect($analyticsModule->settings['ga4_conversion_events'])->toBe('TEST_LIVE_01');

    // Secret must NOT be stored in plaintext in the database column
    expect($analyticsModule->settings['ga4_service_account_json'])->not->toBe($this->serviceCredentials);
    // Secret can be successfully decrypted
    expect(Crypt::decryptString($analyticsModule->settings['ga4_service_account_json']))->toBe($this->serviceCredentials);

    // Helper function transparently returns decrypted value
    expect(module_setting('analytics', 'ga4_measurement_id'))->toBe('G-ABC1234567');
    expect(module_setting('analytics', 'ga4_service_account_json'))->toBe($this->serviceCredentials);
});

test('updating settings with blank secret retains the existing encrypted secret', function (): void {
    $analyticsModule = CmsModule::where('identifier', 'analytics')->firstOrFail();
    $encryptedToken = Crypt::encryptString($this->serviceCredentials);
    $analyticsModule->update([
        'settings' => [
            'ga4_measurement_id' => 'G-EXIST12345',
            'ga4_service_account_json' => $encryptedToken,
        ],
    ]);

    // Update with empty ga4_service_account_json
    $response = $this->actingAs($this->admin, 'web')
        ->put(route('admin.modules.settings.update', ['identifier' => 'analytics']), [
            'ga4_measurement_id' => 'G-NEW1234567',
            'ga4_service_account_json' => '',
        ]);

    $response->assertRedirect(route('admin.modules.settings', ['identifier' => 'analytics']));

    $analyticsModule->refresh();
    expect($analyticsModule->settings['ga4_measurement_id'])->toBe('G-NEW1234567');
    expect($analyticsModule->settings['ga4_service_account_json'])->toBe($encryptedToken);
    expect(module_setting('analytics', 'ga4_service_account_json'))->toBe($this->serviceCredentials);
});

test('module_setting helper falls back to runtime config then provided default', function (): void {
    // When no setting exists in DB
    $nonExistent = module_setting('analytics', 'custom_key', 'fallback_value');
    expect($nonExistent)->toBe('fallback_value');

    // When runtime config exists
    config(['modules.analytics.custom_config_key' => 'from_config']);
    expect(module_setting('analytics', 'custom_config_key'))->toBe('from_config');
});

test('modules index view displays settings link and configuration badges', function (): void {
    $response = $this->actingAs($this->admin, 'web')
        ->get(route('admin.modules.index'));

    $response->assertStatus(200);
    $response->assertSee('Ajustes');
    $response->assertSee(route('admin.modules.settings', ['identifier' => 'analytics']));
});

test('forms index renders preview button and interactive modal with form engine', function (): void {
    // Create a custom form with a field
    $form = CustomForm::create([
        'slug' => 'formulario-auditoria-tecnica',
        'is_active' => true,
    ]);

    CustomFormTranslation::create([
        'custom_form_id' => $form->id,
        'locale' => 'es',
        'name' => 'Formulario de Auditoría Técnica',
        'submit_button_text' => 'Solicitar Auditoría',
        'success_message' => 'Solicitud de auditoría recibida con éxito.',
    ]);

    $field = FormField::create([
        'custom_form_id' => $form->id,
        'field_key' => 'empresa',
        'type' => 'text',
        'is_required' => true,
        'order' => 1,
    ]);

    FormFieldTranslation::create([
        'form_field_id' => $field->id,
        'locale' => 'es',
        'label' => 'Nombre de la Empresa',
    ]);

    $response = $this->actingAs($this->admin, 'web')
        ->get(route('admin.forms.index'));

    $response->assertStatus(200);
    $response->assertSee('Ver Formulario');
    $response->assertSee('previewFormId');
    $response->assertSee('Vista Previa: Formulario de Auditoría Técnica');
    $response->assertSee('Nombre de la Empresa');
    $response->assertSee('Solicitar Auditoría');
    $response->assertSee('&lt;x-forms::form-engine slug="formulario-auditoria-tecnica" /&gt;', false);
});

test('admin can view settings of an installed inactive module and sees the inactive notice', function (): void {
    CmsModule::where('identifier', 'analytics')->update(['is_active' => false, 'is_installed' => true]);

    $response = $this->actingAs($this->admin, 'web')
        ->get(route('admin.modules.settings', ['identifier' => 'analytics']));

    $response->assertStatus(200);
    $response->assertSee(__('admin.modules.settings_inactive_notice'), false);
    $response->assertSee('GA4 Measurement ID');
});

test('admin can update settings of an installed inactive module', function (): void {
    CmsModule::where('identifier', 'analytics')->update(['is_active' => false, 'is_installed' => true]);

    $response = $this->actingAs($this->admin, 'web')
        ->put(route('admin.modules.settings.update', ['identifier' => 'analytics']), [
            'ga4_measurement_id' => 'G-INACTIVE12',
            'ga4_service_account_json' => $this->serviceCredentials,
            'ga4_conversion_events' => 'TEST_INACTIVE',
        ]);

    $response->assertRedirect(route('admin.modules.settings', ['identifier' => 'analytics']));
    $response->assertSessionHas('success');
    expect(module_setting('analytics', 'ga4_measurement_id'))->toBe('G-INACTIVE12');
});

test('settings of a module that is not installed return 404', function (): void {
    CmsModule::where('identifier', 'analytics')->update(['is_installed' => false, 'is_active' => false]);

    $response = $this->actingAs($this->admin, 'web')
        ->get(route('admin.modules.settings', ['identifier' => 'analytics']));

    $response->assertNotFound();
});
