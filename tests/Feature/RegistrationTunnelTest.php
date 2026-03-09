<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-290 / ADR-300: Registration Tunnel tests.
 *
 * Covers:
 *   - Public fields endpoint returns company-scope fields for a jobdomain
 *   - Public fields endpoint filters by market applicability
 *   - Public fields excludes legal_form from logistique preset (ADR-300)
 *   - Public addons endpoint returns addon modules (ADR-300)
 *   - Registration with dynamic_fields saves FieldValues
 *   - Registration with legal_status_key sets Company column
 *   - Registration with billing_same_as_company copies address (ADR-300)
 *   - Registration with billing_same_as_company=false preserves separate values (ADR-300)
 *   - Backward compat: registration without dynamic_fields works
 */
class RegistrationTunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        ModuleRegistry::sync();
        PlanRegistry::sync();
        JobdomainRegistry::sync();
        FieldDefinitionCatalog::sync();
    }

    // ═══════════════════════════════════════════════════════
    // 1) Public fields returns company-scope fields for logistique
    // ═══════════════════════════════════════════════════════

    public function test_public_fields_returns_company_scope_fields_for_logistique(): void
    {
        $response = $this->getJson('/api/public/fields?jobdomain=logistique&market=FR');

        $response->assertOk()
            ->assertJsonStructure(['fields' => [['code', 'label', 'type', 'group', 'order']]]);

        $fields = collect($response->json('fields'));

        // Should have company-scope fields from the logistique preset
        $this->assertTrue($fields->contains('code', 'siret'), 'siret should be present for FR market');
        $this->assertTrue($fields->contains('code', 'billing_address'), 'billing_address should be present');
        $this->assertTrue($fields->contains('code', 'company_address'), 'company_address should be present');
        $this->assertTrue($fields->contains('code', 'company_phone'), 'company_phone should be present');

        // Verify grouping
        $siret = $fields->firstWhere('code', 'siret');
        $this->assertEquals('general', $siret['group']);

        $billingAddr = $fields->firstWhere('code', 'billing_address');
        $this->assertEquals('billing', $billingAddr['group']);

        $companyAddr = $fields->firstWhere('code', 'company_address');
        $this->assertEquals('address', $companyAddr['group']);

        $phone = $fields->firstWhere('code', 'company_phone');
        $this->assertEquals('contact', $phone['group']);
    }

    // ═══════════════════════════════════════════════════════
    // 2) Public fields filters by market applicability
    // ═══════════════════════════════════════════════════════

    public function test_public_fields_filters_by_market_applicability(): void
    {
        // siret has applicable_markets: ['FR'] — should be excluded for GB
        $response = $this->getJson('/api/public/fields?jobdomain=logistique&market=GB');

        $response->assertOk();

        $fields = collect($response->json('fields'));
        $this->assertFalse($fields->contains('code', 'siret'), 'siret should be excluded for GB market');

        // billing_address has no market restriction — should still be present
        $this->assertTrue($fields->contains('code', 'billing_address'), 'billing_address should be present for any market');
    }

    // ═══════════════════════════════════════════════════════
    // 3) ADR-300: legal_form excluded from logistique preset
    // ═══════════════════════════════════════════════════════

    public function test_public_fields_excludes_legal_form_from_logistique(): void
    {
        $response = $this->getJson('/api/public/fields?jobdomain=logistique&market=FR');

        $response->assertOk();

        $fields = collect($response->json('fields'));
        $this->assertFalse($fields->contains('code', 'legal_form'), 'legal_form should be excluded from logistique preset (ADR-300)');
    }

    // ═══════════════════════════════════════════════════════
    // 4) ADR-300: Public addons endpoint returns addon modules
    // ═══════════════════════════════════════════════════════

    public function test_public_addons_returns_addon_modules_for_plan(): void
    {
        $response = $this->getJson('/api/public/addons?jobdomain=logistique&plan=pro');

        $response->assertOk()
            ->assertJsonStructure(['addons', 'currency']);

        // Response shape is valid (may be empty if no addons configured)
        $this->assertIsArray($response->json('addons'));
        $this->assertNotNull($response->json('currency'));
    }

    // ═══════════════════════════════════════════════════════
    // 5) Register with dynamic_fields saves FieldValues
    // ═══════════════════════════════════════════════════════

    public function test_register_with_dynamic_fields_saves_field_values(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@tunnel.test',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Tunnel Test Co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'dynamic_fields' => [
                'siret' => '12345678901234',
                'billing_city' => 'Paris',
                'company_phone' => '+33612345678',
            ],
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Tunnel Test Co')->first();
        $this->assertNotNull($company);

        // Verify field values were saved
        $siretValue = FieldValue::whereHas('definition', fn ($q) => $q->where('code', 'siret'))
            ->where('model_type', 'company')
            ->where('model_id', $company->id)
            ->first();
        $this->assertNotNull($siretValue);
        $this->assertEquals('12345678901234', $siretValue->value);

        $billingCity = FieldValue::whereHas('definition', fn ($q) => $q->where('code', 'billing_city'))
            ->where('model_type', 'company')
            ->where('model_id', $company->id)
            ->first();
        $this->assertNotNull($billingCity);
        $this->assertEquals('Paris', $billingCity->value);
    }

    // ═══════════════════════════════════════════════════════
    // 6) Register with legal_status_key sets Company column
    // ═══════════════════════════════════════════════════════

    public function test_register_with_legal_status_key_sets_company_column(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Pierre',
            'last_name' => 'Martin',
            'email' => 'pierre@tunnel.test',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Legal Status Co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'legal_status_key' => 'sas',
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Legal Status Co')->first();
        $this->assertNotNull($company);
        $this->assertEquals('sas', $company->legal_status_key);
    }

    // ═══════════════════════════════════════════════════════
    // 7) ADR-300: billing_same_as_company copies address fields
    // ═══════════════════════════════════════════════════════

    public function test_register_billing_same_copies_address(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Sophie',
            'last_name' => 'Bernard',
            'email' => 'sophie@tunnel.test',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Billing Same Co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'billing_same_as_company' => true,
            'dynamic_fields' => [
                'company_address' => '10 rue de la Paix',
                'company_city' => 'Paris',
                'company_postal_code' => '75002',
            ],
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Billing Same Co')->first();
        $this->assertNotNull($company);

        // Verify billing address was copied from company address
        $billingAddress = FieldValue::whereHas('definition', fn ($q) => $q->where('code', 'billing_address'))
            ->where('model_type', 'company')
            ->where('model_id', $company->id)
            ->first();
        $this->assertNotNull($billingAddress, 'billing_address should be auto-copied');
        $this->assertEquals('10 rue de la Paix', $billingAddress->value);

        $billingCity = FieldValue::whereHas('definition', fn ($q) => $q->where('code', 'billing_city'))
            ->where('model_type', 'company')
            ->where('model_id', $company->id)
            ->first();
        $this->assertNotNull($billingCity, 'billing_city should be auto-copied');
        $this->assertEquals('Paris', $billingCity->value);
    }

    // ═══════════════════════════════════════════════════════
    // 8) ADR-300: billing_same_as_company=false preserves separate values
    // ═══════════════════════════════════════════════════════

    public function test_register_billing_different_preserves_values(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Luc',
            'last_name' => 'Durand',
            'email' => 'luc@tunnel.test',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Billing Diff Co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'billing_same_as_company' => false,
            'dynamic_fields' => [
                'company_address' => '10 rue de la Paix',
                'company_city' => 'Paris',
                'billing_address' => '5 avenue des Champs',
                'billing_city' => 'Lyon',
            ],
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Billing Diff Co')->first();
        $this->assertNotNull($company);

        // Billing address should NOT be overwritten by company address
        $billingCity = FieldValue::whereHas('definition', fn ($q) => $q->where('code', 'billing_city'))
            ->where('model_type', 'company')
            ->where('model_id', $company->id)
            ->first();
        $this->assertNotNull($billingCity);
        $this->assertEquals('Lyon', $billingCity->value);

        $billingAddress = FieldValue::whereHas('definition', fn ($q) => $q->where('code', 'billing_address'))
            ->where('model_type', 'company')
            ->where('model_id', $company->id)
            ->first();
        $this->assertNotNull($billingAddress);
        $this->assertEquals('5 avenue des Champs', $billingAddress->value);
    }

    // ═══════════════════════════════════════════════════════
    // 9) Register without dynamic_fields works (backward compat)
    // ═══════════════════════════════════════════════════════

    public function test_register_without_dynamic_fields_backward_compat(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Simple',
            'last_name' => 'User',
            'email' => 'simple@tunnel.test',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Simple Co',
            'jobdomain_key' => 'logistique',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user', 'company']);

        $this->assertDatabaseHas('companies', ['name' => 'Simple Co']);
    }
}
