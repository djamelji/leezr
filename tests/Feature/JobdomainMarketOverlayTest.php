<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainMarketOverlay;
use App\Core\Jobdomains\JobdomainPresetResolver;
use App\Core\Jobdomains\ResolvedPresets;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobdomainMarketOverlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SystemSeeder::class);
        JobdomainPresetResolver::clearCache();
    }

    // ── Resolve without overlay (global defaults) ──────────────

    public function test_resolve_global_returns_jobdomain_defaults(): void
    {
        $presets = JobdomainPresetResolver::resolve('logistique');

        $this->assertInstanceOf(ResolvedPresets::class, $presets);
        $this->assertEquals('logistique', $presets->jobdomainKey);
        $this->assertNull($presets->marketKey);
        $this->assertNotEmpty($presets->modules);
        $this->assertNotEmpty($presets->fields);
        $this->assertNotEmpty($presets->roles);
    }

    public function test_resolve_unknown_jobdomain_returns_empty(): void
    {
        $presets = JobdomainPresetResolver::resolve('nonexistent');

        $this->assertEmpty($presets->modules);
        $this->assertEmpty($presets->fields);
        $this->assertEmpty($presets->documents);
        $this->assertEmpty($presets->roles);
    }

    public function test_resolve_with_null_market_returns_global(): void
    {
        $global = JobdomainPresetResolver::resolve('logistique', null);
        $explicit = JobdomainPresetResolver::resolve('logistique');

        $this->assertEquals($explicit->modules, $global->modules);
        $this->assertEquals($explicit->fields, $global->fields);
    }

    // ── Module merge rules ─────────────────────────────────────

    public function test_resolve_modules_union(): void
    {
        $result = JobdomainPresetResolver::resolveModules(
            ['core.theme', 'core.members'],
            ['logistics_shipments', 'core.members'],
            null,
        );

        $this->assertEqualsCanonicalizing(
            ['core.theme', 'core.members', 'logistics_shipments'],
            $result,
        );
    }

    public function test_resolve_modules_remove(): void
    {
        $result = JobdomainPresetResolver::resolveModules(
            ['core.theme', 'core.members', 'logistics_shipments'],
            null,
            ['logistics_shipments'],
        );

        $this->assertEqualsCanonicalizing(['core.theme', 'core.members'], $result);
    }

    public function test_resolve_modules_override_and_remove(): void
    {
        $result = JobdomainPresetResolver::resolveModules(
            ['core.theme', 'core.members'],
            ['logistics_shipments'],
            ['core.members'],
        );

        $this->assertEqualsCanonicalizing(['core.theme', 'logistics_shipments'], $result);
    }

    // ── Field merge rules ──────────────────────────────────────

    public function test_resolve_fields_merge_by_code(): void
    {
        $global = [
            ['code' => 'first_name', 'order' => 1],
            ['code' => 'last_name', 'order' => 2],
        ];

        $override = [
            ['code' => 'last_name', 'order' => 10], // Override order
            ['code' => 'phone', 'order' => 3],       // Add new
        ];

        $result = JobdomainPresetResolver::resolveFields($global, $override, null, 'logistique');

        $codes = array_column($result, 'code');
        $this->assertContains('first_name', $codes);
        $this->assertContains('last_name', $codes);
        $this->assertContains('phone', $codes);

        // last_name should have overridden order
        $lastNameEntry = collect($result)->firstWhere('code', 'last_name');
        $this->assertEquals(10, $lastNameEntry['order']);
    }

    public function test_resolve_fields_remove_by_code(): void
    {
        $global = [
            ['code' => 'first_name', 'order' => 1],
            ['code' => 'last_name', 'order' => 2],
            ['code' => 'phone', 'order' => 3],
        ];

        $result = JobdomainPresetResolver::resolveFields($global, null, ['phone'], 'test_jd');

        $codes = array_column($result, 'code');
        $this->assertContains('first_name', $codes);
        $this->assertContains('last_name', $codes);
        $this->assertNotContains('phone', $codes);
    }

    public function test_resolve_fields_mandatory_guard_prevents_removal(): void
    {
        // Create a field definition that is mandatory for 'logistique'
        $mandatoryField = FieldDefinition::create([
            'code' => 'test_mandatory_field',
            'label' => 'Test Mandatory',
            'type' => 'text',
            'scope' => FieldDefinition::SCOPE_COMPANY_USER,
            'validation_rules' => ['required_by_jobdomains' => ['logistique']],
        ]);

        $global = [
            ['code' => 'test_mandatory_field', 'order' => 1],
            ['code' => 'removable_field', 'order' => 2],
        ];

        $result = JobdomainPresetResolver::resolveFields(
            $global,
            null,
            ['test_mandatory_field', 'removable_field'],
            'logistique',
        );

        $codes = array_column($result, 'code');

        // Mandatory field must NOT be removed
        $this->assertContains('test_mandatory_field', $codes);
        // Non-mandatory field should be removed
        $this->assertNotContains('removable_field', $codes);
    }

    // ── Document merge rules ───────────────────────────────────

    public function test_resolve_documents_merge_by_code(): void
    {
        $global = [
            ['code' => 'id_card', 'order' => 1],
            ['code' => 'kbis', 'order' => 2],
        ];

        $override = [
            ['code' => 'kbis', 'order' => 10],
            ['code' => 'insurance', 'order' => 3],
        ];

        $result = JobdomainPresetResolver::resolveDocuments($global, $override, null);

        $codes = array_column($result, 'code');
        $this->assertContains('id_card', $codes);
        $this->assertContains('kbis', $codes);
        $this->assertContains('insurance', $codes);

        $kbisEntry = collect($result)->firstWhere('code', 'kbis');
        $this->assertEquals(10, $kbisEntry['order']);
    }

    public function test_resolve_documents_remove(): void
    {
        $global = [
            ['code' => 'id_card', 'order' => 1],
            ['code' => 'kbis', 'order' => 2],
        ];

        $result = JobdomainPresetResolver::resolveDocuments($global, null, ['kbis']);

        $codes = array_column($result, 'code');
        $this->assertContains('id_card', $codes);
        $this->assertNotContains('kbis', $codes);
    }

    // ── Role merge rules ───────────────────────────────────────

    public function test_resolve_roles_deep_merge(): void
    {
        $global = [
            'manager' => ['name' => 'Manager', 'is_administrative' => true, 'bundles' => ['core.members']],
            'driver' => ['name' => 'Driver', 'is_administrative' => false, 'bundles' => ['logistics.view']],
        ];

        $override = [
            'driver' => ['name' => 'Chauffeur', 'bundles' => ['logistics.view', 'logistics.edit']],
            'ops' => ['name' => 'Operations', 'is_administrative' => false, 'bundles' => ['logistics.manage']],
        ];

        $result = JobdomainPresetResolver::resolveRoles($global, $override, null);

        $this->assertArrayHasKey('manager', $result);
        $this->assertArrayHasKey('driver', $result);
        $this->assertArrayHasKey('ops', $result);

        // driver should be deep-merged
        $this->assertEquals('Chauffeur', $result['driver']['name']);
        $this->assertEquals(['logistics.view', 'logistics.edit'], $result['driver']['bundles']);
    }

    public function test_resolve_roles_remove(): void
    {
        $global = [
            'manager' => ['name' => 'Manager', 'is_administrative' => true],
            'driver' => ['name' => 'Driver', 'is_administrative' => false],
        ];

        $result = JobdomainPresetResolver::resolveRoles($global, null, ['driver']);

        $this->assertArrayHasKey('manager', $result);
        $this->assertArrayNotHasKey('driver', $result);
    }

    public function test_resolve_roles_mandatory_guard_prevents_admin_removal(): void
    {
        $global = [
            'manager' => ['name' => 'Manager', 'is_administrative' => true],
            'driver' => ['name' => 'Driver', 'is_administrative' => false],
        ];

        $result = JobdomainPresetResolver::resolveRoles($global, null, ['manager', 'driver']);

        // Administrative role must NOT be removed
        $this->assertArrayHasKey('manager', $result);
        // Non-administrative role should be removed
        $this->assertArrayNotHasKey('driver', $result);
    }

    // ── Full overlay integration ───────────────────────────────

    public function test_resolve_with_market_overlay(): void
    {
        $jobdomain = Jobdomain::where('key', 'logistique')->firstOrFail();

        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'GB',
            'override_modules' => ['gb_compliance'],
            'remove_modules' => ['logistics_shipments'],
            'override_fields' => [['code' => 'ni_number', 'order' => 50]],
            'remove_fields' => null,
            'override_documents' => null,
            'remove_documents' => ['kbis'],
            'override_roles' => ['driver' => ['name' => 'Driver UK']],
            'remove_roles' => null,
        ]);

        $presets = JobdomainPresetResolver::resolve('logistique', 'GB');

        $this->assertEquals('logistique', $presets->jobdomainKey);
        $this->assertEquals('GB', $presets->marketKey);

        // Module: gb_compliance added, logistics_shipments removed
        $this->assertContains('gb_compliance', $presets->modules);
        $this->assertNotContains('logistics_shipments', $presets->modules);
        // Core modules should still be present
        $this->assertContains('core.theme', $presets->modules);

        // Field: ni_number added
        $fieldCodes = array_column($presets->fields, 'code');
        $this->assertContains('ni_number', $fieldCodes);

        // Document: kbis removed
        $docCodes = array_column($presets->documents, 'code');
        $this->assertNotContains('kbis', $docCodes);
    }

    public function test_resolve_without_overlay_for_market_returns_global(): void
    {
        // No overlay created for GB
        $global = JobdomainPresetResolver::resolve('logistique');

        JobdomainPresetResolver::clearCache();
        $withMarket = JobdomainPresetResolver::resolve('logistique', 'GB');

        // Should be the same as global (no overlay exists)
        $this->assertEquals($global->modules, $withMarket->modules);
        $this->assertEquals($global->fields, $withMarket->fields);
        $this->assertEquals($global->documents, $withMarket->documents);
    }

    // ── Static cache ───────────────────────────────────────────

    public function test_cache_is_hit_on_second_call(): void
    {
        $first = JobdomainPresetResolver::resolve('logistique', 'FR');
        $second = JobdomainPresetResolver::resolve('logistique', 'FR');

        // Same object reference (cached)
        $this->assertSame($first, $second);
    }

    public function test_clear_cache_forces_fresh_resolve(): void
    {
        $first = JobdomainPresetResolver::resolve('logistique', 'FR');

        JobdomainPresetResolver::clearCache();

        $second = JobdomainPresetResolver::resolve('logistique', 'FR');

        // Different object (cache cleared)
        $this->assertNotSame($first, $second);
        // But same data
        $this->assertEquals($first->modules, $second->modules);
    }

    // ── assignToCompany uses resolver ──────────────────────────

    public function test_assign_to_company_uses_market_overlay(): void
    {
        $owner = User::factory()->create();
        $company = Company::create([
            'name' => 'UK Logistics',
            'slug' => 'uk-logistics',
            'market_key' => 'GB',
            'jobdomain_key' => 'logistique',
        ]);
        $company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        // Create overlay that adds a GB-specific module
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'GB',
            'override_modules' => ['core.theme'], // Just ensure overlap is fine
            'remove_modules' => null,
        ]);

        JobdomainPresetResolver::clearCache();

        $jobdomain = JobdomainGate::assignToCompany($company, 'logistique');

        $this->assertEquals('logistique', $company->fresh()->jobdomain_key);
        $this->assertNotNull($jobdomain);
    }

    public function test_assign_to_company_without_overlay_uses_global(): void
    {
        $owner = User::factory()->create();
        $company = Company::create([
            'name' => 'FR Logistics',
            'slug' => 'fr-logistics',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);
        $company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $jobdomain = JobdomainGate::assignToCompany($company, 'logistique');

        $this->assertEquals('logistique', $company->fresh()->jobdomain_key);

        // Should have activated modules from global defaults
        $activatedModules = CompanyModule::where('company_id', $company->id)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        $this->assertNotEmpty($activatedModules);
    }

    // ── Model relations ────────────────────────────────────────

    public function test_overlay_model_belongs_to_jobdomain(): void
    {
        $overlay = JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        $this->assertNotNull($overlay->jobdomain);
        $this->assertEquals('logistique', $overlay->jobdomain->key);
    }

    public function test_jobdomain_has_many_overlays(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'GB',
        ]);

        $jobdomain = Jobdomain::where('key', 'logistique')->first();
        $this->assertCount(2, $jobdomain->overlays);
    }

    public function test_overlay_unique_constraint(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);
    }
}
