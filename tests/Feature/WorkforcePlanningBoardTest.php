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
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G4A (ADR-644) — tableau Planning : shifts + absences (W-G3) + chevauchements, scope manager,
 * filtres serveur, dates bornées, brouillon/publié, shifts à cheval sur minuit, comparaison
 * prévu/réalisé, isolation tenant, RBAC.
 */
class WorkforcePlanningBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $deptA;
    private Employee $manager;
    private Employee $report;
    private Employee $stranger;
    private LeaveType $cp;

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

        $this->company = Company::create(['name' => 'Plan Co', 'slug' => 'plan-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        $this->deptA = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Alpha', 'sort_order' => 0]);
        $this->manager = $this->emp('Mona', null, $this->deptA->id);
        $this->report = $this->emp('Remy', $this->manager->id, $this->deptA->id);
        $this->stranger = $this->emp('Sam', null, null);
        $this->cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'Congés payés', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
    }

    private function emp(string $f, ?int $mgr, ?int $dept): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => $f, 'last_name' => 'X', 'status' => 'active',
            'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'department_id' => $dept, 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function shift(Employee $e, string $date, string $in = '09:00', string $out = '17:00', string $status = 'published', ?string $endDate = null): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date,
            'start_at' => "{$date} {$in}:00", 'end_at' => ($endDate ?? $date)." {$out}:00",
            'timezone' => 'Europe/Paris', 'status' => $status, 'template_snapshot' => ['break_duration_minutes' => 60],
        ]);
    }

    private function leave(Employee $e, string $status, string $from, string $to): LeaveRequest
    {
        return LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->cp->id,
            'date_from' => $from, 'date_to' => $to, 'days_count_hundredths' => 100, 'status' => $status, 'reason' => 'x',
        ]);
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

    private function board(User $u, string $qs = '')
    {
        return $this->actingAs($u)->getJson('/api/workforce/planning/shifts/board?from=2026-06-01&to=2026-06-30'.$qs, ['X-Company-Id' => $this->company->id]);
    }

    public function test_board_returns_shifts_and_absences_for_hr(): void
    {
        $this->shift($this->report, '2026-06-02');
        $this->leave($this->stranger, 'approved', '2026-06-10', '2026-06-12');
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);

        $res = $this->board($hr)->assertOk();
        $this->assertCount(1, $res->json('shifts'));
        $this->assertCount(1, $res->json('absences'));
    }

    public function test_board_scope_manager_sees_only_team(): void
    {
        $this->shift($this->report, '2026-06-02');
        $this->shift($this->stranger, '2026-06-03');
        // Manager « lecture » : planning_view seul (ni manage/publish, ni view+manage) → scopé.
        $mgr = $this->user(['workforce.planning_view'], $this->manager);

        $res = $this->board($mgr)->assertOk();
        $names = collect($res->json('shifts'))->pluck('employee_name')->all();
        $this->assertContains('Remy X', $names);
        $this->assertNotContains('Sam X', $names);
    }

    public function test_board_flags_conflict_with_approved_leave(): void
    {
        $this->shift($this->report, '2026-06-11'); // pendant l'absence
        $this->leave($this->report, 'approved', '2026-06-10', '2026-06-12');
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);

        $res = $this->board($hr)->assertOk();
        $this->assertTrue($res->json('shifts.0.has_conflict'));
        $this->assertCount(1, $res->json('conflicts'));
    }

    public function test_pending_leave_is_not_a_conflict(): void
    {
        $this->shift($this->report, '2026-06-11');
        $this->leave($this->report, 'pending', '2026-06-10', '2026-06-12');
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);

        $res = $this->board($hr)->assertOk();
        $this->assertFalse($res->json('shifts.0.has_conflict'));
        $this->assertCount(0, $res->json('conflicts'));
    }

    public function test_board_filters_by_department(): void
    {
        $this->shift($this->report, '2026-06-02');   // dept Alpha
        $this->shift($this->stranger, '2026-06-03'); // sans dept
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);

        $res = $this->board($hr, '&department_id='.$this->deptA->id)->assertOk();
        $this->assertCount(1, $res->json('shifts'));
    }

    public function test_board_is_bounded_by_dates(): void
    {
        $this->shift($this->report, '2026-07-15'); // hors plage juin
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->assertCount(0, $this->board($hr)->assertOk()->json('shifts'));
    }

    public function test_board_distinguishes_draft_and_published(): void
    {
        $this->shift($this->report, '2026-06-02', status: 'draft');
        $this->shift($this->report, '2026-06-03', status: 'published');
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);

        $shifts = collect($this->board($hr)->assertOk()->json('shifts'));
        $this->assertFalse($shifts->firstWhere('status', 'draft')['is_published']);
        $this->assertTrue($shifts->firstWhere('status', 'published')['is_published']);
    }

    public function test_shift_crossing_midnight_preserved(): void
    {
        $this->shift($this->report, '2026-06-05', '22:00', '06:00', 'published', '2026-06-06');
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);

        $shift = $this->board($hr)->assertOk()->json('shifts.0');
        $this->assertStringContainsString('2026-06-05', $shift['start']);
        $this->assertStringContainsString('2026-06-06', $shift['end'], 'la fin tombe le lendemain (pas de décalage silencieux)');
    }

    public function test_comparison_planned_vs_actual(): void
    {
        $this->shift($this->report, '2026-06-02'); // 09-17 avec 60 min pause → 420 planifié
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id, 'date' => '2026-06-02',
            'clock_in' => '2026-06-02 09:00:00', 'clock_out' => '2026-06-02 17:00:00', 'status' => TimeEntry::STATUS_COMPLETED,
            'total_worked_minutes' => 420, 'total_break_minutes' => 60, 'source' => 'manual',
        ]);
        // report avec shift mais AUCUN pointage → missing.
        $this->shift($this->manager, '2026-06-04');

        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $res = $this->actingAs($hr)->getJson('/api/workforce/planning/shifts/comparison?from=2026-06-01&to=2026-06-30', ['X-Company-Id' => $this->company->id])->assertOk();

        $byEmp = collect($res->json())->keyBy('employee_id');
        $this->assertSame(420, $byEmp[$this->report->id]['planned_minutes']);
        $this->assertSame(420, $byEmp[$this->report->id]['actual_minutes']);
        $this->assertSame(0, $byEmp[$this->report->id]['gap_minutes']);
        $this->assertFalse($byEmp[$this->report->id]['missing']);
        $this->assertTrue($byEmp[$this->manager->id]['missing'], 'planifié sans pointage → manquant');
    }

    public function test_tenant_isolation(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-plan', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $oe = Employee::withoutGlobalScopes()->create(['company_id' => $other->id, 'first_name' => 'O', 'last_name' => 'Y', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'OO']);
        Shift::withoutGlobalScopes()->create(['company_id' => $other->id, 'employee_id' => $oe->id, 'date' => '2026-06-02', 'start_at' => '2026-06-02 09:00:00', 'end_at' => '2026-06-02 17:00:00', 'timezone' => 'Europe/Paris', 'status' => 'published', 'template_snapshot' => []]);

        $this->shift($this->report, '2026-06-02');
        $hr = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->assertCount(1, $this->board($hr)->assertOk()->json('shifts'));
    }

    public function test_requires_planning_view_permission(): void
    {
        $noPerm = $this->user(['workforce.view']);
        $this->board($noPerm)->assertForbidden();
    }
}
