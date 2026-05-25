<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforceTimesheetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private TimesheetPeriod $draftTimesheet;
    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);
        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33',
            'flag_code' => 'fr', 'flag_svg' => '',
        ]);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);

        $this->company = Company::create([
            'name' => 'W3 Test Co', 'slug' => 'w3-test-co',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR',
        ]);
        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id,
            'module_key' => 'workforce',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);
        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id,
            'module_key' => 'workforce_payroll',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);

        $this->user = User::factory()->create();
        Membership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'email' => 'marie@w3test.com',
            'employee_number' => 'EMP-W3',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->draftTimesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-11',
            'period_end' => '2026-05-17',
            'status' => TimesheetPeriod::STATUS_DRAFT,
            'total_worked_minutes' => 2400,
            'total_break_minutes' => 300,
            'total_overtime_minutes' => 0,
            'anomaly_count' => 0,
        ]);

        // Add a timesheet line so submit use case won't reject empty timesheet
        TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'timesheet_period_id' => $this->draftTimesheet->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-12',
            'worked_minutes' => 480,
            'break_minutes' => 60,
            'source_snapshot' => json_encode(['clock_in' => '08:00', 'clock_out' => '17:00']),
        ]);

        $this->leaveType = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'CP',
            'name' => 'Congés Payés',
            'accrual_mode' => LeaveType::ACCRUAL_MONTHLY,
            'annual_entitlement_hundredths' => 2500,
            'requires_approval' => true,
            'is_paid' => true,
            'is_system' => false,
            'enabled' => true,
            'sort_order' => 1,
        ]);

        Model::reguard();
    }

    private function api(string $method, string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        $headers = ['X-Company-Id' => $this->company->id];

        if ($method === 'getJson') {
            return $this->actingAs($this->user)->getJson($url, $headers);
        }

        return $this->actingAs($this->user)->{$method}($url, $data, $headers);
    }

    // ══════════════════════════════════════════════════════════
    // TIMESHEET WORKFLOW
    // ══════════════════════════════════════════════════════════

    public function test_submit_transitions_draft_to_submitted(): void
    {
        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/submit");
        $response->assertOk();
        $this->assertEquals('submitted', $response->json('status'));
    }

    public function test_approve_transitions_submitted_to_approved(): void
    {
        $this->draftTimesheet->update(['status' => 'submitted']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/approve", [
            'approval_note' => 'Looks good',
        ]);
        $response->assertOk();
        $this->assertEquals('approved', $response->json('status'));
    }

    public function test_reject_transitions_submitted_to_rejected(): void
    {
        $this->draftTimesheet->update(['status' => 'submitted']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/reject", [
            'rejection_reason' => 'Missing entries for Monday',
        ]);
        $response->assertOk();
        $this->assertEquals('rejected', $response->json('status'));
    }

    public function test_reject_requires_reason(): void
    {
        $this->draftTimesheet->update(['status' => 'submitted']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/reject");
        $response->assertStatus(422);
    }

    public function test_lock_transitions_approved_to_locked(): void
    {
        $this->draftTimesheet->update(['status' => 'approved']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/lock");
        $response->assertOk();
        $this->assertEquals('locked', $response->json('status'));
    }

    public function test_reopen_transitions_rejected_to_draft(): void
    {
        $this->draftTimesheet->update(['status' => 'rejected']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/reopen");
        $response->assertOk();
        $this->assertEquals('draft', $response->json('status'));
    }

    public function test_cannot_approve_draft_directly(): void
    {
        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/approve");
        $response->assertStatus(422);
    }

    public function test_cannot_lock_submitted(): void
    {
        $this->draftTimesheet->update(['status' => 'submitted']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/lock");
        $response->assertStatus(422);
    }

    public function test_locked_is_terminal(): void
    {
        $this->draftTimesheet->update(['status' => 'locked']);

        $response = $this->api('postJson', "/api/workforce/timesheets/{$this->draftTimesheet->id}/reopen");
        $response->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════
    // LEAVE TYPE CRUD
    // ══════════════════════════════════════════════════════════

    public function test_list_leave_types(): void
    {
        $response = $this->api('getJson', '/api/workforce/leaves/types');
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json()));
    }

    public function test_list_all_leave_types_includes_disabled(): void
    {
        LeaveType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'code' => 'DIS', 'name' => 'Disabled',
            'accrual_mode' => 'none', 'is_system' => false, 'enabled' => false, 'sort_order' => 99,
        ]);

        $response = $this->api('getJson', '/api/workforce/leaves/types?all=1');
        $response->assertOk();
        $codes = collect($response->json())->pluck('code')->toArray();
        $this->assertContains('DIS', $codes);
    }

    public function test_create_leave_type(): void
    {
        $response = $this->api('postJson', '/api/workforce/leaves/types', [
            'code' => 'RTT',
            'name' => 'RTT',
            'accrual_mode' => 'annual',
            'annual_entitlement_hundredths' => 1200,
            'requires_approval' => false,
            'is_paid' => true,
        ]);
        $response->assertStatus(201);
        $this->assertEquals('RTT', $response->json('code'));
        $this->assertFalse($response->json('is_system'));
    }

    public function test_update_leave_type(): void
    {
        $response = $this->api('putJson', "/api/workforce/leaves/types/{$this->leaveType->id}", [
            'name' => 'CP Modifiés',
        ]);
        $response->assertOk();
        $this->assertEquals('CP Modifiés', $response->json('name'));
    }

    public function test_cannot_update_system_leave_type(): void
    {
        $this->leaveType->update(['is_system' => true]);

        $response = $this->api('putJson', "/api/workforce/leaves/types/{$this->leaveType->id}", [
            'name' => 'Illegal',
        ]);
        $response->assertStatus(422);
    }

    public function test_delete_leave_type(): void
    {
        $response = $this->api('deleteJson', "/api/workforce/leaves/types/{$this->leaveType->id}");
        $response->assertOk();
        $this->assertNull(LeaveType::withoutGlobalScopes()->find($this->leaveType->id));
    }

    public function test_cannot_delete_leave_type_with_requests(): void
    {
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-05',
            'days_count_hundredths' => 500,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $response = $this->api('deleteJson', "/api/workforce/leaves/types/{$this->leaveType->id}");
        $response->assertStatus(422);
    }

    public function test_cannot_delete_system_leave_type(): void
    {
        $this->leaveType->update(['is_system' => true]);

        $response = $this->api('deleteJson', "/api/workforce/leaves/types/{$this->leaveType->id}");
        $response->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════
    // LEAVE CALENDAR
    // ══════════════════════════════════════════════════════════

    public function test_calendar_returns_approved_leaves(): void
    {
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-05',
            'days_count_hundredths' => 500,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $response = $this->api('getJson', '/api/workforce/leaves/calendar?from=2026-06-01&to=2026-06-30');
        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_calendar_excludes_rejected_leaves(): void
    {
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-06-10',
            'date_to' => '2026-06-12',
            'days_count_hundredths' => 300,
            'status' => LeaveRequest::STATUS_REJECTED,
        ]);

        $response = $this->api('getJson', '/api/workforce/leaves/calendar?from=2026-06-01&to=2026-06-30');
        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_calendar_requires_date_range(): void
    {
        $response = $this->api('getJson', '/api/workforce/leaves/calendar');
        $response->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════
    // CROSS-COMPANY ISOLATION
    // ══════════════════════════════════════════════════════════

    public function test_cannot_access_other_company_timesheet(): void
    {
        Model::unguard();
        $other = Company::create([
            'name' => 'Other Co', 'slug' => 'other-co',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR',
        ]);
        $otherTs = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-04',
            'period_end' => '2026-05-10',
            'status' => 'draft',
        ]);
        Model::reguard();

        $response = $this->api('postJson', "/api/workforce/timesheets/{$otherTs->id}/submit");
        $response->assertStatus(404);
    }
}
