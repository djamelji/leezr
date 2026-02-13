<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformJobdomainTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->platformAdmin = PlatformUser::create([
            'name' => 'Test Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ─── CRUD ────────────────────────────────────────────

    public function test_can_list_jobdomains(): void
    {
        Jobdomain::create([
            'key' => 'test_jd',
            'label' => 'Test JD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/jobdomains');

        $response->assertOk()
            ->assertJsonStructure(['jobdomains'])
            ->assertJsonCount(1, 'jobdomains');
    }

    public function test_can_show_jobdomain(): void
    {
        $jd = Jobdomain::create([
            'key' => 'show_me',
            'label' => 'Show Me',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk()
            ->assertJsonPath('jobdomain.key', 'show_me')
            ->assertJsonPath('jobdomain.label', 'Show Me');
    }

    public function test_can_create_jobdomain(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'coiffure',
                'label' => 'Coiffure',
                'description' => 'Hair salons',
                'default_modules' => ['core.members'],
                'default_fields' => [
                    ['code' => 'siret', 'required' => true, 'order' => 0],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('jobdomain.key', 'coiffure')
            ->assertJsonPath('jobdomain.label', 'Coiffure');

        $this->assertDatabaseHas('jobdomains', ['key' => 'coiffure']);
    }

    public function test_cannot_create_duplicate_key(): void
    {
        Jobdomain::create([
            'key' => 'duplicate_key',
            'label' => 'Original',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'duplicate_key',
                'label' => 'Duplicate',
            ]);

        $response->assertStatus(422);
    }

    public function test_can_update_jobdomain(): void
    {
        $jd = Jobdomain::create([
            'key' => 'update_me',
            'label' => 'Old Label',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'label' => 'New Label',
                'description' => 'Updated desc',
                'default_modules' => ['core.members', 'core.settings'],
                'default_fields' => [
                    ['code' => 'siret', 'required' => true, 'order' => 0],
                    ['code' => 'phone', 'required' => false, 'order' => 1],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('jobdomain.label', 'New Label');

        $jd->refresh();
        $this->assertEquals(['core.members', 'core.settings'], $jd->default_modules);
        $this->assertCount(2, $jd->default_fields);
        $this->assertEquals('siret', $jd->default_fields[0]['code']);
        $this->assertTrue($jd->default_fields[0]['required']);
        $this->assertEquals('phone', $jd->default_fields[1]['code']);
    }

    public function test_can_delete_unassigned_jobdomain(): void
    {
        $jd = Jobdomain::create([
            'key' => 'delete_me',
            'label' => 'Delete Me',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('jobdomains', ['key' => 'delete_me']);
    }

    public function test_cannot_delete_if_assigned_to_company(): void
    {
        $jd = Jobdomain::create([
            'key' => 'assigned_jd',
            'label' => 'Assigned',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company->jobdomains()->sync([$jd->id]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete: this job domain is assigned to 1 company(ies).');
    }

    // ─── Field preset validation ─────────────────────────

    public function test_default_fields_reject_platform_user_scope(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'bad_fields',
                'label' => 'Bad Fields',
                'default_fields' => [
                    ['code' => 'internal_note', 'required' => false, 'order' => 0],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_default_fields_reject_nonexistent_code(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'bad_codes',
                'label' => 'Bad Codes',
                'default_fields' => [
                    ['code' => 'nonexistent_field_xyz', 'required' => false, 'order' => 0],
                ],
            ]);

        $response->assertStatus(422);
    }

    // ─── Preset application ──────────────────────────────

    public function test_assign_applies_db_preset_fields(): void
    {
        $jd = Jobdomain::create([
            'key' => 'preset_test',
            'label' => 'Preset Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [
                ['code' => 'siret', 'required' => true, 'order' => 0],
                ['code' => 'phone', 'required' => false, 'order' => 1],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Preset Co', 'slug' => 'preset-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'preset_test');

        $activations = FieldActivation::where('company_id', $company->id)->get();
        $activatedCodes = $activations->map(function ($a) {
            return FieldDefinition::find($a->field_definition_id)->code;
        })->toArray();

        $this->assertContains('siret', $activatedCodes);
        $this->assertContains('phone', $activatedCodes);
    }

    public function test_assign_applies_required_and_order_from_preset(): void
    {
        $jd = Jobdomain::create([
            'key' => 'structured_test',
            'label' => 'Structured Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [
                ['code' => 'siret', 'required' => true, 'order' => 5],
                ['code' => 'phone', 'required' => false, 'order' => 10],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Structured Co', 'slug' => 'structured-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'structured_test');

        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $siretActivation = FieldActivation::where('company_id', $company->id)
            ->where('field_definition_id', $siretDef->id)
            ->first();

        $this->assertTrue($siretActivation->required_override);
        $this->assertEquals(5, $siretActivation->order);

        $phoneDef = FieldDefinition::where('code', 'phone')->first();
        $phoneActivation = FieldActivation::where('company_id', $company->id)
            ->where('field_definition_id', $phoneDef->id)
            ->first();

        $this->assertFalse($phoneActivation->required_override);
        $this->assertEquals(10, $phoneActivation->order);
    }

    public function test_updating_presets_does_not_modify_existing_companies(): void
    {
        $jd = Jobdomain::create([
            'key' => 'isolation_test',
            'label' => 'Isolation Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [
                ['code' => 'siret', 'required' => false, 'order' => 0],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Isolation Co', 'slug' => 'isolation-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'isolation_test');

        $countBefore = FieldActivation::where('company_id', $company->id)->count();

        // Now update jobdomain presets to also include phone
        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'default_fields' => [
                    ['code' => 'siret', 'required' => false, 'order' => 0],
                    ['code' => 'phone', 'required' => false, 'order' => 1],
                ],
            ]);

        // Company activations should NOT have changed
        $countAfter = FieldActivation::where('company_id', $company->id)->count();
        $this->assertEquals($countBefore, $countAfter);
    }

    // ─── Permission ──────────────────────────────────────

    public function test_requires_manage_jobdomains_permission(): void
    {
        $unprivileged = PlatformUser::create([
            'name' => 'No Perms',
            'email' => 'noperms@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response = $this->actingAs($unprivileged, 'platform')
            ->getJson('/api/platform/jobdomains');

        $response->assertStatus(403);
    }
}
