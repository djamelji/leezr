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
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-G3 (ADR-643) — calendrier des congés : scope manager (vue), filtres serveur, statuts (refusé
 * non affiché comme absence), gating du MOTIF, approbation/refus inline (use cases existants),
 * anti-double-écriture, isolation tenant, RBAC.
 */
class WorkforceLeaveCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $manager;
    private Employee $report;
    private Employee $stranger;
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

        $this->company = Company::create(['name' => 'Cal Co', 'slug' => 'cal-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        $this->manager = $this->emp('Mona', 'Manager', null);
        $this->report = $this->emp('Remy', 'Report', $this->manager->id);
        $this->stranger = $this->emp('Sam', 'Stranger', null);

        $this->cp = LeaveType::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'Congés payés', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);

        // Solde pour report (permet l'approbation) : accrual 2500.
        LeaveLedgerEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id, 'leave_type_id' => $this->cp->id,
            'entry_type' => 'accrual', 'credit' => 2500, 'debit' => 0, 'balance_after' => 2500,
            'correlation_id' => Str::uuid()->toString(), 'period_key' => '2026-01', 'recorded_at' => now(),
        ]);
    }

    private function emp(string $f, string $l, ?int $mgr): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => $f, 'last_name' => $l, 'status' => 'active',
            'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function leave(Employee $e, string $status, string $from = '2026-06-01', string $to = '2026-06-05', string $reason = 'Vacances'): LeaveRequest
    {
        return LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->cp->id,
            'date_from' => $from, 'date_to' => $to, 'days_count_hundredths' => 500, 'status' => $status, 'reason' => $reason,
        ]);
    }

    private function userWith(array $perms, ?Employee $asEmployee = null): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($asEmployee) {
            $asEmployee->update(['user_id' => $user->id]);
        }

        return $user;
    }

    private function cal(User $u, string $qs = '')
    {
        return $this->actingAs($u)->getJson('/api/workforce/leaves/calendar?from=2026-06-01&to=2026-06-30'.$qs, ['X-Company-Id' => $this->company->id]);
    }

    public function test_hr_sees_all_in_calendar(): void
    {
        $this->leave($this->report, 'approved');
        $this->leave($this->stranger, 'approved');
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);

        $res = $this->cal($hr)->assertOk();
        $this->assertCount(2, $res->json());
    }

    public function test_manager_sees_only_team_in_calendar(): void
    {
        $this->leave($this->report, 'approved');
        $this->leave($this->stranger, 'approved');
        // Manager « lecture seule » : leave_request, pas de view-all → scope soi + rapports.
        $mgr = $this->userWith(['workforce.leave_request'], $this->manager);

        $res = $this->cal($mgr)->assertOk();
        $names = collect($res->json())->pluck('employee_name')->all();
        $this->assertContains('Remy Report', $names);
        $this->assertNotContains('Sam Stranger', $names);
    }

    public function test_rejected_not_shown_as_absence_by_default(): void
    {
        $this->leave($this->report, 'rejected');
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->assertCount(0, $this->cal($hr)->assertOk()->json());
    }

    public function test_calendar_filters_by_leave_type_and_status(): void
    {
        $this->leave($this->report, 'pending');
        $this->leave($this->stranger, 'approved');
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);

        // Statut pending uniquement → 1 résultat.
        $res = $this->cal($hr, '&statuses[]=pending')->assertOk();
        $this->assertCount(1, $res->json());
        $this->assertSame('pending', $res->json('0.status'));

        // Type CP → les 2 (même type).
        $this->assertCount(2, $this->cal($hr, '&leave_type_id='.$this->cp->id)->assertOk()->json());
    }

    public function test_calendar_never_exposes_reason(): void
    {
        $this->leave($this->report, 'approved', reason: 'Motif médical confidentiel');
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $res = $this->cal($hr)->assertOk();
        $this->assertStringNotContainsString('médical', $res->getContent());
        $this->assertStringNotContainsString('reason', $res->getContent());
    }

    public function test_reason_hidden_for_team_viewer_visible_for_hr_and_self(): void
    {
        $lr = $this->leave($this->report, 'pending', reason: 'Raison perso');

        // Manager lecture seule (voit l'absence, pas le motif).
        $mgr = $this->userWith(['workforce.leave_request'], $this->manager);
        $res = $this->actingAs($mgr)->getJson("/api/workforce/leaves/{$lr->id}", ['X-Company-Id' => $this->company->id])->assertOk();
        $this->assertNull($res->json('reason'));
        $this->assertTrue($res->json('reason_hidden'));

        // HR (vue globale) voit le motif.
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $this->actingAs($hr)->getJson("/api/workforce/leaves/{$lr->id}", ['X-Company-Id' => $this->company->id])->assertOk()->assertJsonFragment(['reason' => 'Raison perso']);

        // Le collaborateur lui-même voit son motif.
        $self = $this->userWith(['workforce.leave_request'], $this->report);
        $this->actingAs($self)->getJson("/api/workforce/leaves/{$lr->id}", ['X-Company-Id' => $this->company->id])->assertOk()->assertJsonFragment(['reason' => 'Raison perso']);
    }

    public function test_approve_inline_then_double_approve_fails(): void
    {
        $lr = $this->leave($this->report, 'pending');
        $approver = $this->userWith(['workforce.leave_request', 'workforce.leave_approve']);

        $this->actingAs($approver)->postJson("/api/workforce/leaves/{$lr->id}/approve", [], ['X-Company-Id' => $this->company->id])
            ->assertOk()->assertJsonFragment(['status' => 'approved']);

        // Double approbation : la transition pending→approved n'est plus valide (pas de double ledger).
        $this->actingAs($approver)->postJson("/api/workforce/leaves/{$lr->id}/approve", [], ['X-Company-Id' => $this->company->id])
            ->assertStatus(422);
        $this->assertSame(1, LeaveLedgerEntry::withoutGlobalScopes()->where('leave_type_id', $this->cp->id)->where('debit', '>', 0)->count());
    }

    public function test_reject_inline(): void
    {
        $lr = $this->leave($this->report, 'pending');
        $approver = $this->userWith(['workforce.leave_request', 'workforce.leave_approve']);

        $this->actingAs($approver)->postJson("/api/workforce/leaves/{$lr->id}/reject", ['review_note' => 'Période chargée'], ['X-Company-Id' => $this->company->id])
            ->assertOk()->assertJsonFragment(['status' => 'rejected']);
    }

    public function test_approve_requires_approve_permission(): void
    {
        $lr = $this->leave($this->report, 'pending');
        $viewer = $this->userWith(['workforce.leave_request'], $this->manager);
        $this->actingAs($viewer)->postJson("/api/workforce/leaves/{$lr->id}/approve", [], ['X-Company-Id' => $this->company->id])->assertForbidden();
    }

    public function test_tenant_isolation_calendar(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-cal', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $otherEmp = Employee::withoutGlobalScopes()->create(['company_id' => $other->id, 'first_name' => 'X', 'last_name' => 'Y', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'OX']);
        $otherType = LeaveType::withoutGlobalScopes()->create(['company_id' => $other->id, 'code' => 'CP', 'name' => 'CP', 'accrual_mode' => 'monthly', 'annual_entitlement_hundredths' => 2500]);
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $other->id, 'employee_id' => $otherEmp->id, 'leave_type_id' => $otherType->id, 'date_from' => '2026-06-10', 'date_to' => '2026-06-12', 'days_count_hundredths' => 300, 'status' => 'approved', 'reason' => 'x']);

        $this->leave($this->report, 'approved');
        $hr = $this->userWith(['workforce.leave_request', 'workforce.leave_view_all']);
        $res = $this->cal($hr)->assertOk();
        $this->assertCount(1, $res->json(), 'ne voit que sa propre Company');
    }
}
