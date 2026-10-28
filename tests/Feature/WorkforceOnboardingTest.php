<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Onboarding\WorkforceOnboarding;
use App\Modules\Workforce\Onboarding\WorkforceOnboardingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-637 (W-F) — onboarding RH : contexte conditionnel (modules), état persistant & reprise, étapes
 * non destructives, garde métier, report, finalisation, RBAC, isolation tenant.
 */
class WorkforceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_leave', 'name' => 'Leave', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();
        \App\Modules\Workforce\Organization\OrganizationPreset::create([
            'key' => 'logi_lm_v1', 'label' => 'LM', 'jobdomain_key' => 'logistique', 'market_key' => 'FR',
            'org_profile_key' => 'last_mile_delivery', 'version' => 1, 'status' => 'published', 'published_at' => now(),
            'structure' => ['departments' => [['key' => 'direction', 'name' => 'Direction', 'parent_key' => null]], 'job_roles' => []],
        ]);
    }

    private function company(array $activeModules = ['workforce', 'workforce_leave']): Company
    {
        $c = Company::create(['name' => 'OB Co '.uniqid(), 'slug' => 'ob-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'org_profile_key' => 'last_mile_delivery']);
        foreach ($activeModules as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    private function dept(Company $c, string $name = 'Direction'): Department
    {
        return Department::withoutGlobalScopes()->create(['company_id' => $c->id, 'name' => $name, 'source' => 'company']);
    }

    public function test_new_company_state_and_context(): void
    {
        $s = WorkforceOnboardingService::stateWithContext($this->company());
        $this->assertSame('not_started', $s['state']['status']);
        $this->assertSame('last_mile_delivery', $s['context']['company']['org_profile_key']);
        $this->assertContains('leave', $s['context']['applicable_steps'], 'leave actif → étape présente');
        $this->assertTrue($s['context']['has_preset'] ?? false);
        $this->assertFalse($s['context']['existing_org']['has_org']);
    }

    public function test_leave_step_hidden_when_module_inactive(): void
    {
        $s = WorkforceOnboardingService::stateWithContext($this->company(['workforce']));
        $this->assertNotContains('leave', $s['context']['applicable_steps'], 'leave inactif → étape masquée');
        $this->assertContains('worktime', $s['context']['applicable_steps']);
    }

    public function test_start_moves_to_in_progress_persisted(): void
    {
        $c = $this->company();
        WorkforceOnboardingService::start($c);
        $this->assertDatabaseHas('workforce_onboardings', ['company_id' => $c->id, 'status' => 'in_progress', 'current_step' => 'company']);
    }

    public function test_organization_step_requires_existing_org(): void
    {
        $c = $this->company();
        // Sans organisation → garde métier (422 via abort).
        try {
            WorkforceOnboardingService::completeStep($c, 'organization');
            $this->fail('should have aborted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        // Avec organisation → OK.
        $this->dept($c);
        $r = WorkforceOnboardingService::completeStep($c, 'organization');
        $this->assertContains('organization', $r['state']['completed_steps']);
    }

    public function test_complete_step_is_idempotent(): void
    {
        $c = $this->company();
        $this->dept($c);
        WorkforceOnboardingService::completeStep($c, 'organization');
        WorkforceOnboardingService::completeStep($c, 'organization');
        $state = WorkforceOnboarding::withoutGlobalScopes()->where('company_id', $c->id)->first();
        $this->assertSame(['organization'], $state->completed_steps, 'pas de doublon d\'étape');
    }

    public function test_defer_step(): void
    {
        $c = $this->company();
        $r = WorkforceOnboardingService::deferStep($c, 'roles');
        $this->assertContains('roles', $r['state']['deferred_steps']);
    }

    public function test_finish_completes_and_dismisses(): void
    {
        $c = $this->company();
        WorkforceOnboardingService::finish($c);
        $this->assertDatabaseHas('workforce_onboardings', ['company_id' => $c->id, 'status' => 'completed']);
        $this->assertNotNull($c->fresh()->onboarding_dismissed_at);
    }

    public function test_resume_persists_across_reload(): void
    {
        $c = $this->company();
        $this->dept($c);
        WorkforceOnboardingService::start($c);
        WorkforceOnboardingService::completeStep($c, 'company');
        WorkforceOnboardingService::completeStep($c, 'organization');
        // "Reconnexion" : relire l'état depuis le serveur.
        $s = WorkforceOnboardingService::stateWithContext($c->fresh());
        $this->assertEqualsCanonicalizing(['company', 'organization'], $s['state']['completed_steps']);
        $this->assertSame('in_progress', $s['state']['status']);
    }

    public function test_existing_company_context_reflects_reality(): void
    {
        $c = $this->company();
        $this->dept($c, 'Exploitation');
        Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => 'A', 'last_name' => 'B', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E1']);
        $s = WorkforceOnboardingService::stateWithContext($c);
        $this->assertTrue($s['context']['existing_org']['has_org']);
        $this->assertSame(1, $s['context']['existing_employees']);
    }

    // ── RBAC HTTP + tenant ──
    private function userRole(Company $c, array $perms, bool $admin): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    public function test_start_requires_manage_show_requires_view(): void
    {
        $c = $this->company();
        $viewer = $this->userRole($c, ['workforce.view'], false);
        $this->actingAs($viewer)->getJson('/api/workforce/onboarding', ['X-Company-Id' => $c->id])->assertOk();
        $this->actingAs($viewer)->postJson('/api/workforce/onboarding/start', [], ['X-Company-Id' => $c->id])->assertForbidden();

        $manager = $this->userRole($c, ['workforce.view', 'workforce.manage'], true);
        $this->actingAs($manager)->postJson('/api/workforce/onboarding/start', [], ['X-Company-Id' => $c->id])->assertOk();
    }

    public function test_tenant_isolation(): void
    {
        $a = $this->company();
        WorkforceOnboardingService::start($a);
        WorkforceOnboardingService::finish($a);
        $b = $this->company();
        $s = WorkforceOnboardingService::stateWithContext($b);
        $this->assertSame('not_started', $s['state']['status'], 'état propre à chaque Company');
    }
}
