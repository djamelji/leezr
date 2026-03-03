<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentMandatoryContext;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\MandatoryContext;
use App\Core\Fields\TagDictionary;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-170 Phase 2→4: Tag-based mandatory resolution tests.
 *
 * Validates:
 * - Custom role with archetype driver → license mandatory via tags
 * - Custom role without archetype → license non-mandatory via tags
 * - System role with required_tags → tags are sole role-based mandatory mechanism
 * - Hybrid tags = union (multiple tags on one role)
 * - Backfill command produces correct results
 */
class TagBasedMandatoryTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $admin;
    private User $member;
    private Company $company;
    private CompanyRole $adminRole;
    private $adminMembership;
    private $memberMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Tag Test Co',
            'slug' => 'tag-test-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->activateCompanyModules($this->company);
        $this->adminRole = $this->setUpCompanyRbac($this->company);

        $this->adminMembership = $this->company->memberships()->create([
            'user_id' => $this->admin->id,
            'role' => 'owner',
            'company_role_id' => $this->adminRole->id,
        ]);

        // Activate all company_user fields
        $cuFields = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();
        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }

        // Activate all document types
        $docTypes = DocumentType::where('is_system', true)->get();
        foreach ($docTypes as $index => $docType) {
            DocumentTypeActivation::create([
                'company_id' => $this->company->id,
                'document_type_id' => $docType->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // Custom role WITH archetype → tags mandatory
    // ═══════════════════════════════════════════════════════

    public function test_custom_role_with_driving_tags_makes_field_mandatory(): void
    {
        // work_schedule has tags=['dispatching','driving'] but NO required_by_modules
        // → mandatory ONLY via tag intersection (not polluted by module path)
        $customRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'chauffeur_vl',
            'name' => 'Chauffeur VL',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $customRole->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $response->assertOk();

        $fields = collect($response->json('dynamic_fields'));
        $scheduleField = $fields->firstWhere('code', 'work_schedule');

        $this->assertNotNull($scheduleField, 'work_schedule should be in the response');
        $this->assertTrue($scheduleField['mandatory'], 'work_schedule should be mandatory via driving tag intersection');
    }

    // ═══════════════════════════════════════════════════════
    // Custom role WITHOUT archetype → no tag mandatory
    // ═══════════════════════════════════════════════════════

    public function test_custom_role_without_archetype_no_tag_mandatory(): void
    {
        // work_schedule has tags=['dispatching','driving'] but NO required_by_modules
        // With role 'custom_basic' (no required_tags), work_schedule should NOT be mandatory
        $customRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'custom_basic',
            'name' => 'Custom Basic',
            'archetype' => null,
            'required_tags' => null,
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $customRole->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $response->assertOk();

        $fields = collect($response->json('dynamic_fields'));
        $scheduleField = $fields->firstWhere('code', 'work_schedule');

        $this->assertNotNull($scheduleField, 'work_schedule should be in the response');
        $this->assertFalse($scheduleField['mandatory'], 'work_schedule should NOT be mandatory for a role without required_tags');
    }

    // ═══════════════════════════════════════════════════════
    // Tags are sole role-based mandatory mechanism (Phase 4)
    // ═══════════════════════════════════════════════════════

    public function test_tags_are_sole_role_based_mandatory_mechanism(): void
    {
        // Post-Phase 4: required_by_roles removed, tags are the ONLY role-based path
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_system' => true,
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
        ]);

        // work_schedule has tags=['dispatching','driving'], NO required_by_modules
        $workSchedule = FieldDefinition::whereNull('company_id')
            ->where('code', 'work_schedule')->firstOrFail();

        $mandatoryContext = MandatoryContext::load($this->company->id);

        // With driving tag → mandatory
        $mandatory = MandatoryContext::isMandatory($workSchedule, $mandatoryContext, [TagDictionary::DRIVING]);
        $this->assertTrue($mandatory, 'work_schedule should be mandatory via driving tag');

        // With dispatching tag → mandatory
        $mandatory2 = MandatoryContext::isMandatory($workSchedule, $mandatoryContext, [TagDictionary::DISPATCHING]);
        $this->assertTrue($mandatory2, 'work_schedule should be mandatory via dispatching tag');

        // Without tags → not mandatory via role path
        $mandatory3 = MandatoryContext::isMandatory($workSchedule, $mandatoryContext, null);
        $this->assertFalse($mandatory3, 'work_schedule should NOT be mandatory without required_tags');
    }

    // ═══════════════════════════════════════════════════════
    // Hybrid tags = union
    // ═══════════════════════════════════════════════════════

    public function test_hybrid_role_with_multiple_tags_union(): void
    {
        $hybridRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'polyvalent',
            'name' => 'Polyvalent',
            'archetype' => null,
            'required_tags' => [TagDictionary::DRIVING, TagDictionary::DISPATCHING],
        ]);

        $mandatoryContext = MandatoryContext::load($this->company->id);

        $licenseField = FieldDefinition::whereNull('company_id')
            ->where('code', 'license_number')->firstOrFail();
        $geoField = FieldDefinition::whereNull('company_id')
            ->where('code', 'geographic_zone')->firstOrFail();
        $workScheduleField = FieldDefinition::whereNull('company_id')
            ->where('code', 'work_schedule')->firstOrFail();

        // license_number has tags=['driving'] → mandatory via driving tag
        $this->assertTrue(
            MandatoryContext::isMandatory($licenseField, $mandatoryContext, $hybridRole->required_tags),
            'license_number mandatory via driving tag in hybrid role',
        );

        // geographic_zone has tags=['dispatching'] → mandatory via dispatching tag
        $this->assertTrue(
            MandatoryContext::isMandatory($geoField, $mandatoryContext, $hybridRole->required_tags),
            'geographic_zone mandatory via dispatching tag in hybrid role',
        );

        // work_schedule has tags=['dispatching', 'driving'] → mandatory via intersection
        $this->assertTrue(
            MandatoryContext::isMandatory($workScheduleField, $mandatoryContext, $hybridRole->required_tags),
            'work_schedule mandatory via intersecting tags in hybrid role',
        );
    }

    // ═══════════════════════════════════════════════════════
    // Document tag-based mandatory
    // ═══════════════════════════════════════════════════════

    public function test_document_mandatory_via_tags(): void
    {
        // medical_certificate has tags=['driving'] but NO required_by_modules
        // → clean test of tag-based mandatory without module path pollution
        $medCert = DocumentType::where('code', 'medical_certificate')->firstOrFail();
        $mandatoryContext = DocumentMandatoryContext::load($this->company->id);

        // Without tags → not mandatory via tags path
        $this->assertFalse(
            DocumentMandatoryContext::isMandatory($medCert, $mandatoryContext, null),
            'medical_certificate should NOT be mandatory without required_tags',
        );

        // With driving tag → mandatory
        $this->assertTrue(
            DocumentMandatoryContext::isMandatory($medCert, $mandatoryContext, [TagDictionary::DRIVING]),
            'medical_certificate should be mandatory via driving tag',
        );
    }

    // ═══════════════════════════════════════════════════════
    // Backfill command
    // ═══════════════════════════════════════════════════════

    public function test_backfill_command_populates_system_roles(): void
    {
        // Create system roles WITHOUT archetype (simulates pre-Phase 2 data)
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_system' => true,
        ]);
        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_system' => true,
        ]);

        $this->assertNull($driverRole->archetype);
        $this->assertNull($dispatcherRole->archetype);

        $this->artisan('role:backfill-archetypes')
            ->assertExitCode(0);

        $driverRole->refresh();
        $dispatcherRole->refresh();

        $this->assertEquals('driver', $driverRole->archetype);
        $this->assertEquals([TagDictionary::DRIVING], $driverRole->required_tags);
        $this->assertEquals('dispatcher', $dispatcherRole->archetype);
        $this->assertEquals([TagDictionary::DISPATCHING], $dispatcherRole->required_tags);
    }

    public function test_backfill_command_is_idempotent(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_system' => true,
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
        ]);

        // Running backfill on already-correct data should skip (0 updated)
        $this->artisan('role:backfill-archetypes')
            ->expectsOutputToContain('Updated: 0')
            ->assertExitCode(0);
    }

    // ═══════════════════════════════════════════════════════
    // Tag resolution never reads archetype directly
    // ═══════════════════════════════════════════════════════

    public function test_archetype_not_used_in_resolution(): void
    {
        // Role has archetype set but required_tags is null
        // → tags should NOT produce mandatory (archetype is not read by resolver)
        $roleWithArchetypeOnly = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'broken_setup',
            'name' => 'Broken Setup',
            'archetype' => 'driver',
            'required_tags' => null,
        ]);

        // work_schedule: tags=['dispatching','driving'], no required_by_modules
        $workSchedule = FieldDefinition::whereNull('company_id')
            ->where('code', 'work_schedule')->firstOrFail();

        $mandatoryContext = MandatoryContext::load($this->company->id);

        // With archetype set but no required_tags, tag path should NOT trigger
        $mandatory = MandatoryContext::isMandatory(
            $workSchedule,
            $mandatoryContext,
            $roleWithArchetypeOnly->required_tags,
        );

        $this->assertFalse($mandatory, 'Archetype alone must NOT trigger tag-based mandatory — only required_tags matters');
    }
}
