<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValidationService;
use App\Core\Fields\FieldWriteService;
use App\Core\Fields\FieldValue;
use App\Core\Fields\PhoneNormalizerService;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-165: Market Hardening — field system market awareness + phone E.164.
 */
class MarketAwareFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $frCompany;
    private Company $gbCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();

        $this->user = User::factory()->create();

        // FR company
        $this->frCompany = Company::create(['name' => 'FR Co', 'slug' => 'fr-co', 'market_key' => 'FR', 'jobdomain_key' => 'logistique']);
        $this->frCompany->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);

        // GB company
        $this->gbCompany = Company::create(['name' => 'GB Co', 'slug' => 'gb-co', 'market_key' => 'GB', 'jobdomain_key' => 'logistique']);
        $this->gbCompany->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);

        // Activate all fields for both companies
        $allDefs = FieldDefinition::whereNull('company_id')->get();
        foreach ([$this->frCompany, $this->gbCompany] as $company) {
            foreach ($allDefs as $index => $def) {
                if ($def->scope === FieldDefinition::SCOPE_PLATFORM_USER) {
                    continue;
                }
                FieldActivation::create([
                    'company_id' => $company->id,
                    'field_definition_id' => $def->id,
                    'enabled' => true,
                    'required_override' => false,
                    'order' => $index * 10,
                ]);
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Market-aware field resolution
    // ═══════════════════════════════════════════════════════

    public function test_resolve_shows_siret_for_fr_company(): void
    {
        $fields = FieldResolverService::resolve(
            $this->frCompany,
            FieldDefinition::SCOPE_COMPANY,
            $this->frCompany->id,
            marketKey: 'FR',
        );

        $codes = array_column($fields, 'code');
        $this->assertContains('siret', $codes, 'SIRET must be visible for FR company');
    }

    public function test_resolve_hides_siret_for_non_fr_company(): void
    {
        $fields = FieldResolverService::resolve(
            $this->gbCompany,
            FieldDefinition::SCOPE_COMPANY,
            $this->gbCompany->id,
            marketKey: 'GB',
        );

        $codes = array_column($fields, 'code');
        $this->assertNotContains('siret', $codes, 'SIRET must be hidden for GB company');
    }

    public function test_resolve_hides_contract_type_for_non_fr_company(): void
    {
        $fields = FieldResolverService::resolve(
            $this->user,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->gbCompany->id,
            marketKey: 'GB',
        );

        $codes = array_column($fields, 'code');
        $this->assertNotContains('contract_type', $codes, 'FR contract types must be hidden for GB company');
    }

    public function test_universal_fields_visible_for_all_markets(): void
    {
        $frFields = FieldResolverService::resolve(
            $this->user,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->frCompany->id,
            marketKey: 'FR',
        );

        $gbFields = FieldResolverService::resolve(
            $this->user,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->gbCompany->id,
            marketKey: 'GB',
        );

        $frCodes = array_column($frFields, 'code');
        $gbCodes = array_column($gbFields, 'code');

        // Universal fields must be visible in both markets
        $this->assertContains('phone', $frCodes);
        $this->assertContains('phone', $gbCodes);
        $this->assertContains('job_title', $frCodes);
        $this->assertContains('job_title', $gbCodes);
    }

    public function test_resolve_without_market_key_shows_all_fields(): void
    {
        $fields = FieldResolverService::resolve(
            $this->gbCompany,
            FieldDefinition::SCOPE_COMPANY,
            $this->gbCompany->id,
        );

        $codes = array_column($fields, 'code');
        $this->assertContains('siret', $codes, 'Without marketKey, all fields must be visible (backward compat)');
    }

    // ═══════════════════════════════════════════════════════
    // Market-aware validation
    // ═══════════════════════════════════════════════════════

    public function test_validation_excludes_market_specific_fields(): void
    {
        $rules = FieldValidationService::rules(
            FieldDefinition::SCOPE_COMPANY,
            $this->gbCompany->id,
            marketKey: 'GB',
        );

        $this->assertArrayNotHasKey('dynamic_fields.siret', $rules, 'SIRET validation must be excluded for GB');
    }

    // ═══════════════════════════════════════════════════════
    // Phone E.164 normalization
    // ═══════════════════════════════════════════════════════

    public function test_phone_normalized_to_e164_on_write(): void
    {
        FieldWriteService::upsert(
            $this->user,
            ['phone' => '06 12 34 56 78'],
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->frCompany->id,
            'FR',
        );

        $phoneDef = FieldDefinition::where('code', 'phone')->first();
        $value = FieldValue::where('field_definition_id', $phoneDef->id)
            ->where('model_type', 'user')
            ->where('model_id', $this->user->id)
            ->first();

        $this->assertSame('+33612345678', $value->value);
    }

    public function test_phone_already_e164_unchanged(): void
    {
        FieldWriteService::upsert(
            $this->user,
            ['phone' => '+33612345678'],
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->frCompany->id,
            'FR',
        );

        $phoneDef = FieldDefinition::where('code', 'phone')->first();
        $value = FieldValue::where('field_definition_id', $phoneDef->id)
            ->where('model_type', 'user')
            ->where('model_id', $this->user->id)
            ->first();

        $this->assertSame('+33612345678', $value->value);
    }

    public function test_phone_normalizer_gb_format(): void
    {
        $this->assertSame('+447911123456', PhoneNormalizerService::normalize('07911 123456', '+44'));
        $this->assertSame('+447911123456', PhoneNormalizerService::normalize('+447911123456', '+44'));
    }

    public function test_write_rejects_market_specific_fields_silently(): void
    {
        // Try to write SIRET for a GB company — should be silently skipped
        FieldWriteService::upsert(
            $this->gbCompany,
            ['siret' => '12345678901234'],
            FieldDefinition::SCOPE_COMPANY,
            $this->gbCompany->id,
            'GB',
        );

        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $value = FieldValue::where('field_definition_id', $siretDef->id)
            ->where('model_type', 'company')
            ->where('model_id', $this->gbCompany->id)
            ->first();

        $this->assertNull($value, 'SIRET write must be silently skipped for GB company');
    }
}
