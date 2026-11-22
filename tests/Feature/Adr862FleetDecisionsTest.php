<?php

namespace Tests\Feature;

use App\Core\Fields\FunctionActivity;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Organization\OrganizationPreset;
use App\Modules\Workforce\Organization\OrganizationPresetService;
use App\Modules\Workforce\Organization\OrganizationPresetStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-862 (Leezr 5.0, Fleet product decisions) — Livreur drives by default WITHOUT overwriting what a company configured ;
 * the operations manager has no automatic obligation ; nobody gets a position automatically ; the occasional driver is
 * declared explicitly on the person (deliveries are a hint) ; no company switches scope by itself.
 */
class Adr862FleetDecisionsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private function preset(): OrganizationPreset
    {
        return OrganizationPreset::create(['key' => 'fleet862_'.uniqid(), 'label' => 'Last mile', 'jobdomain_key' => 'logistique', 'market_key' => null,
            'org_profile_key' => null, 'version' => 1, 'status' => 'published', 'published_at' => now(), 'structure' => [
                'departments' => [['key' => 'ops', 'name' => 'Exploitation', 'parent_key' => null, 'sort_order' => 0]],
                'job_roles' => [
                    ['key' => 'ops_manager', 'title' => "Responsable d'exploitation", 'department_key' => 'ops', 'level' => 'manager'],
                    ['key' => 'driver', 'title' => 'Livreur', 'department_key' => 'ops', 'level' => 'agent', 'activities' => ['driving']],
                ]]]);
    }

    public function test_the_preset_declares_driving_on_livreur_only_and_never_overwrites_a_company_position(): void
    {
        $preset = $this->preset();
        $fresh = Company::create(['name' => 'New Co', 'slug' => 'new-co-'.uniqid()]);
        OrganizationPresetService::apply($fresh, null, $preset);
        $this->assertSame(['driving'], JobRole::withoutGlobalScopes()->where('company_id', $fresh->id)->where('title', 'Livreur')->first()->metadata['activities']);
        $this->assertEmpty(JobRole::withoutGlobalScopes()->where('company_id', $fresh->id)->where('title', "Responsable d'exploitation")->first()->metadata['activities'] ?? []);

        // a company that already has its « Livreur » (customised : declares nothing) keeps it as it is
        $old = Company::create(['name' => 'Old Co', 'slug' => 'old-co-'.uniqid()]);
        $mine = JobRole::withoutGlobalScopes()->create(['company_id' => $old->id, 'title' => 'Livreur', 'metadata' => ['activities' => [], 'note' => 'mine']]);
        $employee = Employee::withoutGlobalScopes()->create(['company_id' => $old->id, 'employee_number' => 'E1', 'first_name' => 'No', 'last_name' => 'Position',
            'email' => 'np@co.test', 'status' => 'active', 'hire_date' => '2026-01-01']);
        OrganizationPresetService::apply($old, null, $preset);
        $this->assertSame(['activities' => [], 'note' => 'mine'], $mine->fresh()->metadata);
        $this->assertNull($employee->fresh()->job_role_id, 'nobody gets a position automatically');
        $this->assertSame(FunctionActivity::SCOPE_EVERYONE, FunctionActivity::scopeOf($old->id), 'no company switches scope by itself');
        $this->assertSame(FunctionActivity::SCOPE_EVERYONE, FunctionActivity::scopeOf($fresh->id));
    }

    public function test_an_unknown_activity_is_refused_in_a_preset(): void
    {
        $errors = OrganizationPresetStructureValidator::validate(['jobdomain_key' => 'logistique', 'structure' => ['departments' => [],
            'job_roles' => [['key' => 'pilot', 'title' => 'Pilote', 'activities' => ['flying']]]]]);
        $this->assertContains('job_role_activity_unknown:pilot', $errors);
    }

    public function test_the_occasional_driver_is_declared_on_the_person_file_with_the_position_right(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $company = Company::create(['name' => 'Decl Co', 'slug' => 'decl-co-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($company);
        \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => 'workforce'], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $company->id, 'employee_number' => 'E2', 'first_name' => 'Occ', 'last_name' => 'Driver',
            'email' => 'occ@co.test', 'status' => 'active', 'hire_date' => '2026-01-01', 'metadata' => ['keep' => 1]]);
        $api = fn (array $body) => $this->actingAs($owner)->withHeaders(['X-Company-Id' => $company->id])->putJson("/api/workforce/employees/{$e->id}", $body);

        $api(['activities' => ['flying']])->assertStatus(422);
        $api(['activities' => ['driving']])->assertOk();
        $this->assertSame(['keep' => 1, 'activities' => ['driving']], $e->fresh()->metadata, 'other metadata kept');
        FunctionActivity::setScope($company->id, FunctionActivity::SCOPE_FUNCTION); // in-test only (the command refuses outside a clone)
        $this->assertContains('driving', FunctionActivity::tagsForEmployee($company->id, $e->fresh()));
        $api(['activities' => []])->assertOk();
        FunctionActivity::flush();
        $this->assertNotContains('driving', FunctionActivity::tagsForEmployee($company->id, $e->fresh()));
    }
}
