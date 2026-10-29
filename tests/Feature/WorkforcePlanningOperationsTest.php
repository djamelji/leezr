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
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Support\WeekStartResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G4B (ADR-645) — gestion opérationnelle : édition brouillon (réaffectation/horaires), verrou
 * optimiste (concurrence), scope manager, protection des shifts publiés, suggestions de
 * disponibilité, premier jour de semaine Company. Réutilise les gardes W-G4A/UpdateShiftUseCase.
 */
class WorkforcePlanningOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $manager;
    private Employee $report;
    private Employee $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_planning', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Ops Co', 'slug' => 'ops-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        $this->manager = $this->emp('Mona', null);
        $this->report = $this->emp('Remy', $this->manager->id);
        $this->stranger = $this->emp('Sam', null);
    }

    private function emp(string $f, ?int $mgr): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => $f, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid()]);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);

        return $e;
    }

    private function draft(Employee $e, string $date = '2026-06-02', string $in = '09:00', string $out = '17:00'): Shift
    {
        return Shift::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date, 'start_at' => "{$date} {$in}:00", 'end_at' => "{$date} {$out}:00", 'timezone' => 'Europe/Paris', 'status' => 'draft', 'template_snapshot' => ['break_duration_minutes' => 60]]);
    }

    private function user(array $perms, ?Employee $asEmployee = null): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($asEmployee) {
            $asEmployee->update(['user_id' => $u->id]);
        }

        return $u;
    }

    private function putShift(User $u, int $id, array $body)
    {
        return $this->actingAs($u)->putJson("/api/workforce/planning/shifts/{$id}", $body, ['X-Company-Id' => $this->company->id]);
    }

    // ── Édition brouillon ──
    public function test_reschedule_draft_shift(): void
    {
        $s = $this->draft($this->report);
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->putShift($hr, $s->id, ['start_at' => '2026-06-02 10:00:00', 'end_at' => '2026-06-02 18:00:00'])->assertOk();
        $this->assertDatabaseHas('workforce_shifts', ['id' => $s->id, 'start_at' => '2026-06-02 10:00:00']);
    }

    public function test_reassign_draft_shift_to_available_employee(): void
    {
        $s = $this->draft($this->report);
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->putShift($hr, $s->id, ['employee_id' => $this->stranger->id])->assertOk();
        $this->assertDatabaseHas('workforce_shifts', ['id' => $s->id, 'employee_id' => $this->stranger->id]);
    }

    // ── Protection publié ──
    public function test_published_shift_cannot_be_edited_directly(): void
    {
        $s = $this->draft($this->report);
        $s->update(['status' => 'published', 'published_at' => now()]);
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->putShift($hr, $s->id, ['start_at' => '2026-06-02 10:00:00', 'end_at' => '2026-06-02 18:00:00'])->assertStatus(422);
    }

    // ── Verrou optimiste ──
    public function test_optimistic_lock_detects_concurrent_modification(): void
    {
        $s = $this->draft($this->report);
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $stale = now()->subMinutes(10)->toIso8601String();

        $this->putShift($hr, $s->id, ['start_at' => '2026-06-02 11:00:00', 'end_at' => '2026-06-02 19:00:00', 'expected_updated_at' => $stale])
            ->assertStatus(409)->assertJsonFragment(['code' => 'stale_version']);
        // Le shift n'a PAS été modifié.
        $this->assertDatabaseHas('workforce_shifts', ['id' => $s->id, 'start_at' => '2026-06-02 09:00:00']);
    }

    public function test_optimistic_lock_passes_with_current_timestamp(): void
    {
        $s = $this->draft($this->report);
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->putShift($hr, $s->id, ['start_at' => '2026-06-02 11:00:00', 'end_at' => '2026-06-02 19:00:00', 'expected_updated_at' => $s->updated_at->toIso8601String()])
            ->assertOk();
    }

    // ── Scope manager ──
    public function test_manager_cannot_reassign_outside_scope(): void
    {
        $s = $this->draft($this->report);
        // Manager « planning_manage » SANS vue globale employés → scope soi + rapports.
        $mgr = $this->user(['workforce.planning_view', 'workforce.planning_manage'], $this->manager);
        // report est un rapport → OK ; stranger hors périmètre → 403.
        $this->putShift($mgr, $s->id, ['employee_id' => $this->stranger->id], )->assertStatus(403);
        $this->assertDatabaseHas('workforce_shifts', ['id' => $s->id, 'employee_id' => $this->report->id]);
    }

    // ── Réaffectation interdite : congé approuvé (garde W-G4A réutilisée) ──
    public function test_reassign_to_employee_on_approved_leave_rejected(): void
    {
        $cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'leave_type_id' => $cp->id, 'date_from' => '2026-06-02', 'date_to' => '2026-06-02', 'days_count_hundredths' => 100, 'status' => 'approved', 'reason' => 'x']);

        $s = $this->draft($this->report);
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->putShift($hr, $s->id, ['employee_id' => $this->stranger->id])->assertStatus(422);
    }

    // ── Disponibilité ──
    public function test_availability_flags_candidates(): void
    {
        // stranger occupé par un shift chevauchant ; report en congé approuvé.
        $this->draft($this->stranger, '2026-06-02', '09:00', '17:00');
        $cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'leave_type_id' => $cp->id, 'date_from' => '2026-06-02', 'date_to' => '2026-06-02', 'days_count_hundredths' => 100, 'status' => 'approved', 'reason' => 'x']);

        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $res = $this->actingAs($hr)->getJson('/api/workforce/planning/shifts/availability?start=2026-06-02 09:00:00&end=2026-06-02 17:00:00', ['X-Company-Id' => $this->company->id])->assertOk();

        $byEmp = collect($res->json())->keyBy('employee_id');
        $this->assertSame('available', $byEmp[$this->manager->id]['availability']);
        $this->assertSame('busy', $byEmp[$this->stranger->id]['availability']);
        $this->assertSame('unavailable', $byEmp[$this->report->id]['availability']);
    }

    public function test_availability_requires_manage_permission(): void
    {
        $viewer = $this->user(['workforce.planning_view']);
        $this->actingAs($viewer)->getJson('/api/workforce/planning/shifts/availability?start=2026-06-02 09:00:00&end=2026-06-02 17:00:00', ['X-Company-Id' => $this->company->id])->assertForbidden();
    }

    // ── Premier jour de semaine ──
    public function test_week_start_resolution_company_then_market(): void
    {
        $this->assertSame(1, WeekStartResolver::resolve($this->company)); // FR → lundi
        $this->company->update(['week_start_day' => 0]);
        $this->assertSame(0, WeekStartResolver::resolve($this->company->fresh())); // préférence explicite
    }

    public function test_week_start_endpoint_get_and_set(): void
    {
        $hr = $this->user(['workforce.view', 'workforce.manage']);
        $this->actingAs($hr)->getJson('/api/workforce/config/week-start', ['X-Company-Id' => $this->company->id])
            ->assertOk()->assertJsonFragment(['week_start_day' => 1, 'source' => 'market']);

        $this->actingAs($hr)->putJson('/api/workforce/config/week-start', ['week_start_day' => 0], ['X-Company-Id' => $this->company->id])
            ->assertOk()->assertJsonFragment(['week_start_day' => 0, 'source' => 'company']);
        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'week_start_day' => 0]);
    }

    public function test_week_start_set_requires_manage(): void
    {
        $viewer = $this->user(['workforce.view']);
        $this->actingAs($viewer)->putJson('/api/workforce/config/week-start', ['week_start_day' => 0], ['X-Company-Id' => $this->company->id])->assertForbidden();
    }
}
