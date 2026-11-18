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
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-780 (UX4.1) — leave balances (and filing a request) follow the SAME perimeter as every other
 * leave read (WorkforceAccess::canViewEmployeeData) : view-all (leave_view_all / leave_approve, owner),
 * self, or direct report. Another company's employee is simply not found (no disclosure).
 */
class Ux41LeaveBalanceScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    private Employee $manager;

    private Employee $report;

    private Employee $stranger;

    private Employee $foreign;

    private LeaveType $cp;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_leave', 'name' => 'Workforce Leave', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = $this->makeCompany('alpha');
        $this->other = $this->makeCompany('beta');
        $this->manager = $this->emp($this->company, 'Mona', null);
        $this->report = $this->emp($this->company, 'Remy', $this->manager->id);
        $this->stranger = $this->emp($this->company, 'Sam', null);
        $this->foreign = $this->emp($this->other, 'Fox', null);
        $this->cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'Congés payés', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
        foreach ([[$this->report, 2500], [$this->stranger, 1700], [$this->manager, 900]] as [$e, $available]) {
            \App\Core\Workforce\LeaveBalanceCache::withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->cp->id, 'period_year' => (int) now()->year,
                'cached_accrued' => $available, 'cached_reserved' => 0, 'cached_consumed' => 0, 'cached_adjusted' => 0, 'cached_available' => $available,
                'last_computed_at' => now(),
            ]);
            LeaveLedgerEntry::withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->cp->id,
                'entry_type' => 'accrual', 'credit' => 2500, 'debit' => 0, 'balance_after' => 2500,
                'correlation_id' => Str::uuid()->toString(), 'period_key' => '2026-01', 'recorded_at' => now(),
            ]);
        }
    }

    private function makeCompany(string $slug): Company
    {
        $c = Company::create(['name' => ucfirst($slug), 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    private function emp(Company $c, string $first, ?int $mgr): Employee
    {
        $e = Employee::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'first_name' => $first, 'last_name' => 'T', 'status' => 'active',
            'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid(),
        ]);
        EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire',
            'status' => 'active', 'is_current' => true, 'start_date' => '2026-01-01', 'weekly_hours' => 35,
        ]);

        return $e;
    }

    private function userWith(array $perms, ?Employee $asEmployee = null, string $role = 'member'): User
    {
        $user = User::factory()->create();
        $r = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $r->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => $role, 'company_role_id' => $r->id]);
        $asEmployee?->update(['user_id' => $user->id]);

        return $user;
    }

    private function balances(User $u, Employee $e)
    {
        return $this->actingAs($u)->getJson("/api/workforce/employees/{$e->id}/leave-balances", ['X-Company-Id' => $this->company->id]);
    }

    private function file(User $u, Employee $e)
    {
        return $this->actingAs($u)->postJson('/api/workforce/leaves', [
            'employee_id' => $e->id, 'leave_type_id' => $this->cp->id, 'date_from' => '2026-12-07', 'date_to' => '2026-12-07', 'days_count_hundredths' => 100,
        ], ['X-Company-Id' => $this->company->id]);
    }

    public function test_owner_reads_any_balance(): void
    {
        $owner = $this->userWith([], null, 'owner');
        $this->balances($owner, $this->stranger)->assertOk()->assertJsonCount(1);
    }

    public function test_hr_with_view_all_reads_any_balance(): void
    {
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->balances($hr, $this->stranger)->assertOk();
        $this->balances($hr, $this->report)->assertOk();
    }

    public function test_hr_without_leave_request_is_refused_by_the_route(): void
    {
        $noLeave = $this->userWith(['workforce.view']);
        $this->balances($noLeave, $this->stranger)->assertForbidden();
    }

    public function test_manager_reads_a_direct_report_only(): void
    {
        $mgr = $this->userWith(['workforce.leave_request'], $this->manager);
        $this->balances($mgr, $this->report)->assertOk()->assertJsonPath('0.available', 2500);
        $this->balances($mgr, $this->stranger)->assertForbidden();
    }

    public function test_employee_reads_herself_only(): void
    {
        $self = $this->userWith(['workforce.leave_request'], $this->stranger);
        $this->balances($self, $this->stranger)->assertOk()->assertJsonPath('0.available', 1700);
        $refused = $this->balances($self, $this->report)->assertForbidden();
        $this->assertStringNotContainsString('2500', $refused->getContent());
    }

    public function test_other_company_employee_is_not_found_and_nothing_leaks(): void
    {
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $res = $this->balances($hr, $this->foreign)->assertNotFound();
        $this->assertStringNotContainsString('Fox', $res->getContent());
    }

    public function test_personal_endpoint_only_returns_own_balances(): void
    {
        $self = $this->userWith(['workforce.leave_request'], $this->stranger);
        $res = $this->actingAs($self)->getJson('/api/workforce/me/leave-balances?employee_id='.$this->report->id, ['X-Company-Id' => $this->company->id])->assertOk();
        // The parameter is ignored : the caller's own balance (1700), never the report's (2500).
        $this->assertSame([1700], collect($res->json('balances'))->pluck('available')->all());
    }

    public function test_filing_a_request_follows_the_same_perimeter(): void
    {
        $mgr = $this->userWith(['workforce.leave_request'], $this->manager);
        $this->file($mgr, $this->report)->assertCreated();
        $this->file($mgr, $this->stranger)->assertForbidden();
        $this->assertSame(0, LeaveRequest::withoutGlobalScopes()->where('employee_id', $this->stranger->id)->count());

        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->file($hr, $this->stranger)->assertCreated();
    }

    // ── ADR-781 (UX5) — statistics follow the same perimeter ─────────────────────────────────────

    private function stats(User $u, ?Company $as = null)
    {
        return $this->actingAs($u)->getJson('/api/workforce/leaves/statistics?year=2026', ['X-Company-Id' => ($as ?? $this->company)->id]);
    }

    private function seedRequests(): void
    {
        foreach ([[$this->report, 'pending'], [$this->stranger, 'pending'], [$this->stranger, 'approved'], [$this->manager, 'approved']] as [$e, $st]) {
            LeaveRequest::withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->cp->id,
                'date_from' => '2026-06-01', 'date_to' => '2026-06-02', 'days_count_hundredths' => 200, 'status' => $st,
            ]);
        }
        $foreignType = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->other->id, 'code' => 'CP', 'name' => 'CP', 'accrual_mode' => 'monthly']);
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->other->id, 'employee_id' => $this->foreign->id, 'leave_type_id' => $foreignType->id,
            'date_from' => '2026-06-01', 'date_to' => '2026-06-02', 'days_count_hundredths' => 200, 'status' => 'pending',
        ]);
    }

    public function test_statistics_whole_company_for_view_all_and_owner_only(): void
    {
        $this->seedRequests();
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->stats($hr)->assertOk()->assertJsonPath('pending.count', 2)->assertJsonPath('approved.count', 2);
        $this->stats($this->userWith([], null, 'owner'))->assertOk()->assertJsonPath('pending.count', 2);
    }

    public function test_statistics_of_a_manager_cover_self_and_direct_reports(): void
    {
        $this->seedRequests();
        $mgr = $this->userWith(['workforce.leave_request'], $this->manager);
        $this->stats($mgr)->assertOk()->assertJsonPath('pending.count', 1)->assertJsonPath('approved.count', 1);
    }

    public function test_statistics_of_an_employee_are_personal_only(): void
    {
        $this->seedRequests();
        $self = $this->userWith(['workforce.leave_request'], $this->report);
        $res = $this->stats($self)->assertOk()->assertJsonPath('pending.count', 1);
        $this->assertNull($res->json('approved'), 'aucun indicateur sur les autres');

        $noRecord = $this->userWith(['workforce.leave_request']);
        $this->assertSame([], $this->stats($noRecord)->assertOk()->json());
        $this->stats($this->userWith(['workforce.view']))->assertForbidden();
    }

    /** ADR-822 — « en attente » = file de travail : une demande déposée pour une année future est comptée. */
    public function test_pending_counter_includes_requests_of_a_future_year(): void
    {
        $this->seedRequests();
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'leave_type_id' => $this->cp->id,
            'date_from' => '2028-06-25', 'date_to' => '2028-06-27', 'days_count_hundredths' => 300, 'status' => 'pending',
        ]);
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->stats($hr)->assertOk()->assertJsonPath('pending.count', 3)->assertJsonPath('approved.count', 2);
    }

    /** ADR-822 — registre des contrats : même périmètre que la liste des salariés, jamais une autre société. */
    public function test_contract_register_follows_the_employee_perimeter(): void
    {
        $get = fn (User $u, string $q = '') => $this->actingAs($u)->getJson('/api/workforce/contracts'.$q, ['X-Company-Id' => $this->company->id]);
        $owner = $this->userWith([], null, 'owner');
        $all = $get($owner)->assertOk();
        $this->assertSame(3, $all->json('total'));
        $this->assertNotContains($this->foreign->id, array_column($all->json('data'), 'employee_id'), 'never another company');
        $this->assertSame(['active' => 3], $all->json('counts'));
        $this->assertArrayNotHasKey('compensation_plans', $all->json('data.0'), 'no compensation in the register');

        $mgr = $this->userWith(['workforce.view'], $this->manager);
        $ids = array_column($get($mgr)->assertOk()->json('data'), 'employee_id');
        sort($ids);
        $this->assertSame([$this->manager->id, $this->report->id], $ids, 'self + direct reports only');

        $this->assertSame(1, $get($owner, '?search=Remy')->json('total'));
        $this->assertSame(0, $get($owner, '?status=draft')->json('total'));
        $get($this->userWith(['workforce.leave_request']))->assertForbidden();
    }

    /** ADR-823 — team balances : same cached balances, limited to the reader's leave perimeter, never another company. */
    public function test_team_balances_follow_the_leave_perimeter(): void
    {
        $get = fn (User $u, ?Company $as = null) => $this->actingAs($u)->getJson('/api/workforce/leaves/balances?year='.now()->year, ['X-Company-Id' => ($as ?? $this->company)->id]);
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $all = collect($get($hr)->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->report->id, $this->stranger->id], $all->pluck('employee_id')->unique()->values()->all());
        $this->assertSame(1700, $all->firstWhere('employee_id', $this->stranger->id)['available'], 'cached ledger balance, no recomputation');
        $mgr = $this->userWith(['workforce.leave_request'], $this->manager);
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->report->id], collect($get($mgr)->assertOk()->json('data'))->pluck('employee_id')->all());
        $this->assertSame([], $get($this->userWith(['workforce.leave_request']))->assertOk()->json('data'), 'no employee record : nothing');
        $get($this->userWith(['workforce.view']))->assertForbidden();
    }

    public function test_statistics_never_count_another_company(): void
    {
        $this->seedRequests();
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->stats($hr)->assertOk()->assertJsonPath('pending.count', 2); // the foreign pending one is not counted
    }
}
