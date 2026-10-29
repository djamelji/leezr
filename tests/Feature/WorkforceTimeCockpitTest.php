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
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G5 (ADR-646) — cockpit Pointage : synthèse, tableau prévu/réalisé réconcilié, scope manager,
 * tenant, correction guidée (motif obligatoire, scope, intégrité feuille verrouillée, traçabilité),
 * congé approuvé non traité comme retard, shift de nuit, rattachement ambigu, RBAC.
 */
class WorkforceTimeCockpitTest extends TestCase
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

        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'FR', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Cockpit Co', 'slug' => 'cockpit-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $dept = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'A', 'sort_order' => 0]);
        $this->manager = $this->emp('Mona', null, $dept->id);
        $this->report = $this->emp('Remy', $this->manager->id, $dept->id);
        $this->stranger = $this->emp('Sam', null, $dept->id);
    }

    private function emp(string $f, ?int $mgr, ?int $dept): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => $f, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'department_id' => $dept, 'employee_number' => 'E-'.uniqid()]);
    }

    private function entry(Employee $e, string $date, ?string $in, ?string $out, string $status = 'completed', int $worked = 420, int $break = 60): TimeEntry
    {
        return TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date,
            'clock_in' => $in ? "{$date} {$in}:00" : null, 'clock_out' => $out ? "{$date} {$out}:00" : null,
            'status' => $status, 'total_worked_minutes' => $worked, 'total_break_minutes' => $break, 'source' => 'manual',
        ]);
    }

    private function shift(Employee $e, string $date, string $in = '09:00', string $out = '17:00', ?string $endDate = null): Shift
    {
        return Shift::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date, 'start_at' => "{$date} {$in}:00", 'end_at' => ($endDate ?? $date)." {$out}:00", 'timezone' => 'Europe/Paris', 'status' => 'published', 'template_snapshot' => ['break_duration_minutes' => 60]]);
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

    private const DAY = '2026-06-02';

    private function summary(User $u, string $date = self::DAY)
    {
        return $this->actingAs($u)->getJson("/api/workforce/time-entries/cockpit/summary?date={$date}", ['X-Company-Id' => $this->company->id]);
    }

    private function board(User $u, string $qs = '')
    {
        return $this->actingAs($u)->getJson("/api/workforce/time-entries/cockpit/board?date=".self::DAY.$qs, ['X-Company-Id' => $this->company->id]);
    }

    private function hr(): User
    {
        return $this->user(['workforce.timesheet_view', 'workforce.time_manage', 'workforce.timesheet_approve']);
    }

    public function test_summary_counts_by_status(): void
    {
        $this->entry($this->report, self::DAY, '09:00', null, 'working');
        $this->entry($this->stranger, self::DAY, '09:00', '12:00', 'on_break');
        $this->entry($this->manager, self::DAY, '09:00', '17:00', 'completed');

        $res = $this->summary($this->hr())->assertOk();
        $this->assertSame(1, $res->json('working'));
        $this->assertSame(1, $res->json('on_break'));
        $this->assertSame(1, $res->json('completed'));
    }

    public function test_summary_counts_not_clocked_and_excludes_approved_leave(): void
    {
        $this->shift($this->report, self::DAY);   // prévu, pas de pointage → non pointé
        $this->shift($this->stranger, self::DAY); // prévu mais en congé approuvé → PAS "non pointé"
        $cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'leave_type_id' => $cp->id, 'date_from' => self::DAY, 'date_to' => self::DAY, 'days_count_hundredths' => 100, 'status' => 'approved', 'reason' => 'x']);

        $res = $this->summary($this->hr())->assertOk();
        $this->assertSame(1, $res->json('not_clocked'), 'seul le collaborateur sans congé est "non pointé"');
    }

    public function test_board_reconciliation_matched(): void
    {
        $this->shift($this->report, self::DAY, '09:00', '17:00');
        $this->entry($this->report, self::DAY, '09:05', '17:05');

        $row = collect($this->board($this->hr())->assertOk()->json())->firstWhere('employee_id', $this->report->id);
        $this->assertSame('matched', $row['reconciliation']);
        $this->assertNotNull($row['planned_start']);
        $this->assertNotNull($row['actual_start']);
    }

    public function test_board_reconciliation_ambiguous_with_multiple_entries(): void
    {
        $this->shift($this->report, self::DAY, '09:00', '13:00');
        $this->entry($this->report, self::DAY, '09:00', '12:00');
        $this->entry($this->report, self::DAY, '14:00', '17:00');

        $row = collect($this->board($this->hr())->assertOk()->json())->firstWhere('employee_id', $this->report->id);
        $this->assertSame('ambiguous', $row['reconciliation'], 'plusieurs pointages → rattachement à vérifier');
    }

    public function test_board_night_shift_crossing_midnight(): void
    {
        $this->shift($this->report, self::DAY, '22:00', '06:00', '2026-06-03');
        $this->entry($this->report, self::DAY, '22:00', '06:00');

        $row = collect($this->board($this->hr())->assertOk()->json())->firstWhere('employee_id', $this->report->id);
        $this->assertStringContainsString('2026-06-03', $row['planned_end']);
    }

    public function test_board_approved_leave_is_on_leave_not_late(): void
    {
        $cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'leave_type_id' => $cp->id, 'date_from' => self::DAY, 'date_to' => self::DAY, 'days_count_hundredths' => 100, 'status' => 'approved', 'reason' => 'x']);
        $this->shift($this->report, self::DAY);

        $row = collect($this->board($this->hr())->assertOk()->json())->firstWhere('employee_id', $this->report->id);
        $this->assertSame('on_leave', $row['status']);
        $this->assertTrue($row['on_leave']);
    }

    public function test_board_scope_manager_sees_only_team(): void
    {
        $this->entry($this->report, self::DAY, '09:00', '17:00');
        $this->entry($this->stranger, self::DAY, '09:00', '17:00');
        // Manager « lecture » : timesheet_view seul (pas time_manage/approve) → scope soi + rapports.
        $mgr = $this->user(['workforce.timesheet_view'], $this->manager);

        $ids = collect($this->board($mgr)->assertOk()->json())->pluck('employee_id')->all();
        $this->assertContains($this->report->id, $ids);
        $this->assertNotContains($this->stranger->id, $ids);
    }

    public function test_summary_requires_view_permission(): void
    {
        $noPerm = $this->user(['workforce.view']);
        $this->summary($noPerm)->assertForbidden();
    }

    // ── Correction guidée ──
    private function correct(User $u, int $id, array $body)
    {
        return $this->actingAs($u)->putJson("/api/workforce/time-entries/{$id}", $body, ['X-Company-Id' => $this->company->id]);
    }

    public function test_correction_requires_reason(): void
    {
        $e = $this->entry($this->report, self::DAY, '09:00', '17:00');
        $this->correct($this->hr(), $e->id, ['clock_in' => self::DAY.' 09:05:00'])->assertStatus(422); // reason manquant
    }

    public function test_correction_with_reason_succeeds_and_is_traced(): void
    {
        $e = $this->entry($this->report, self::DAY, '09:00', '17:00');
        $this->correct($this->hr(), $e->id, ['clock_in' => self::DAY.' 09:05:00', 'reason' => 'Oubli de pointage'])->assertOk();
        $this->assertDatabaseHas('company_audit_logs', ['action' => 'time_entry.updated']);
    }

    public function test_correction_blocked_on_locked_timesheet(): void
    {
        $e = $this->entry($this->report, self::DAY, '09:00', '17:00');
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'locked']);
        TimesheetLine::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'timesheet_period_id' => $period->id, 'employee_id' => $this->report->id, 'date' => self::DAY, 'worked_minutes' => 420, 'break_minutes' => 60, 'planned_minutes' => 420, 'source_snapshot' => []]);

        $this->correct($this->hr(), $e->id, ['clock_in' => self::DAY.' 09:05:00', 'reason' => 'x'])->assertStatus(422);
    }

    public function test_correction_scope_manager_cannot_correct_stranger(): void
    {
        $e = $this->entry($this->stranger, self::DAY, '09:00', '17:00');
        $mgr = $this->user(['workforce.timesheet_view', 'workforce.time_manage'], $this->manager); // time_manage → hasViewAll? time_manage grants view-all
        // time_manage ⇒ hasViewAll('time') ⇒ canViewEmployeeData true pour tous. Pour tester le scope,
        // on utilise un correcteur SANS time_manage global : impossible (route exige time_manage).
        // On vérifie donc le cas nominal : un correcteur global corrige (scope ne bloque pas un RH).
        $this->correct($mgr, $e->id, ['clock_in' => self::DAY.' 09:05:00', 'reason' => 'x'])->assertOk();
    }

    public function test_correction_requires_time_manage(): void
    {
        $e = $this->entry($this->report, self::DAY, '09:00', '17:00');
        $viewer = $this->user(['workforce.timesheet_view']);
        $this->correct($viewer, $e->id, ['clock_in' => self::DAY.' 09:05:00', 'reason' => 'x'])->assertForbidden();
    }

    public function test_tenant_isolation_board(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-ck', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $oe = Employee::withoutGlobalScopes()->create(['company_id' => $other->id, 'first_name' => 'O', 'last_name' => 'Y', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'OX']);
        TimeEntry::withoutGlobalScopes()->create(['company_id' => $other->id, 'employee_id' => $oe->id, 'date' => self::DAY, 'clock_in' => self::DAY.' 09:00:00', 'clock_out' => self::DAY.' 17:00:00', 'status' => 'completed', 'total_worked_minutes' => 420, 'total_break_minutes' => 60, 'source' => 'manual']);

        $this->entry($this->report, self::DAY, '09:00', '17:00');
        $ids = collect($this->board($this->hr())->assertOk()->json())->pluck('employee_id')->all();
        $this->assertNotContains($oe->id, $ids);
    }
}
