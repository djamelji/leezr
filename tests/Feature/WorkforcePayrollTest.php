<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforcePayrollTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_payroll', 'workforce_documents']]);
        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33',
            'flag_code' => 'fr', 'flag_svg' => '',
        ]);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_payroll', 'name' => 'Payroll', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_documents', 'name' => 'Documents', 'is_enabled_globally' => true]);

        $this->company = Company::create([
            'name' => 'W5 Test Co', 'slug' => 'w5-test-co',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR',
        ]);

        foreach (['workforce', 'workforce_payroll', 'workforce_documents'] as $module) {
            CompanyModuleActivationReason::create([
                'company_id' => $this->company->id, 'module_key' => $module,
                'reason' => CompanyModuleActivationReason::REASON_DIRECT,
            ]);
        }

        $this->user = User::factory()->create();
        Membership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'owner']);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'first_name' => 'Marie', 'last_name' => 'Curie',
            'email' => 'marie@w5test.com', 'employee_number' => 'EMP-W5',
            'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE,
        ]);

        EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'contract_type' => 'cdi', 'work_model_key' => 'on_site',
            'start_date' => '2024-01-01', 'weekly_hours' => 35,
            'status' => 'active', 'is_current' => true,
        ]);

        Model::reguard();
    }

    private function apiGet(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson($url, ['X-Company-Id' => $this->company->id]);
    }

    private function apiPost(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson($url, $data, ['X-Company-Id' => $this->company->id]);
    }

    private function apiDel(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->deleteJson($url, [], ['X-Company-Id' => $this->company->id]);
    }

    // ══════════════════════════════════════════════════════════
    // PAYROLL RUNS CRUD
    // ══════════════════════════════════════════════════════════

    public function test_create_payroll_run(): void
    {
        $response = $this->apiPost('/api/workforce/payroll', [
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('draft', $response->json('status'));
    }

    public function test_list_payroll_runs(): void
    {
        $this->createDraftRun();
        $this->apiGet('/api/workforce/payroll')->assertOk();
    }

    public function test_show_payroll_run(): void
    {
        $run = $this->createDraftRun();
        $response = $this->apiGet("/api/workforce/payroll/{$run->id}");
        $response->assertOk();
        $this->assertArrayHasKey('run', $response->json());
        $this->assertArrayHasKey('lines_summary', $response->json());
    }

    public function test_delete_draft_run(): void
    {
        $run = $this->createDraftRun();
        $this->apiDel("/api/workforce/payroll/{$run->id}")->assertStatus(204);
    }

    public function test_cannot_delete_computed_run(): void
    {
        $run = $this->createDraftRun();
        $run->update(['status' => 'computed']);
        $this->apiDel("/api/workforce/payroll/{$run->id}")->assertStatus(422);
    }

    public function test_idempotency_prevents_duplicate_run(): void
    {
        $this->apiPost('/api/workforce/payroll', [
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        ])->assertStatus(201);

        $this->apiPost('/api/workforce/payroll', [
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        ])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════
    // PAYROLL LINES
    // ══════════════════════════════════════════════════════════

    public function test_list_run_lines(): void
    {
        $run = $this->createRunWithLine();
        $response = $this->apiGet("/api/workforce/payroll/{$run->id}/lines");
        $response->assertOk();
    }

    public function test_show_line_detail(): void
    {
        $run = $this->createRunWithLine();
        $line = PayrollLine::withoutGlobalScopes()->where('payroll_run_id', $run->id)->first();

        $response = $this->apiGet("/api/workforce/payroll/{$run->id}/lines/{$line->id}");
        $response->assertOk();
        $this->assertEquals($this->employee->id, $response->json('employee.id'));
        $this->assertArrayHasKey('calculation', $response->json());
    }

    public function test_show_line_detail_wrong_run_returns_404(): void
    {
        $run = $this->createRunWithLine();
        $line = PayrollLine::withoutGlobalScopes()->where('payroll_run_id', $run->id)->first();

        // Create another run
        Model::unguard();
        $otherRun = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'status' => 'draft', 'currency' => 'EUR',
            'idempotency_key' => 'payroll:x:2026-07-01:2026-07-31',
        ]);
        Model::reguard();

        // Line belongs to first run, not otherRun
        $this->apiGet("/api/workforce/payroll/{$otherRun->id}/lines/{$line->id}")->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════
    // CALCULATIONS
    // ══════════════════════════════════════════════════════════

    public function test_list_calculations(): void
    {
        $run = $this->createDraftRun();
        $response = $this->apiGet("/api/workforce/payroll/{$run->id}/calculations");
        $response->assertOk();
        $this->assertArrayHasKey('calculations', $response->json());
        $this->assertArrayHasKey('totals', $response->json());
    }

    // ══════════════════════════════════════════════════════════
    // STATISTICS
    // ══════════════════════════════════════════════════════════

    public function test_statistics(): void
    {
        $this->apiGet('/api/workforce/payroll/statistics')->assertOk();
    }

    // ══════════════════════════════════════════════════════════
    // DOCUMENTS FOR RUN
    // ══════════════════════════════════════════════════════════

    public function test_list_run_documents(): void
    {
        $run = $this->createDraftRun();
        $response = $this->apiGet("/api/workforce/payroll/{$run->id}/documents");
        $response->assertOk();
    }

    // ══════════════════════════════════════════════════════════
    // STATE MACHINE
    // ══════════════════════════════════════════════════════════

    public function test_state_machine_transitions(): void
    {
        $run = $this->createDraftRun();
        $this->assertTrue($run->canTransitionTo('computed'));
        $this->assertFalse($run->canTransitionTo('validated'));
        $this->assertFalse($run->canTransitionTo('exported'));

        $run->transitionTo('computed');
        $this->assertEquals('computed', $run->status);
        $this->assertTrue($run->canTransitionTo('validated'));
        $this->assertTrue($run->canTransitionTo('draft'));

        $run->transitionTo('validated');
        $this->assertEquals('validated', $run->status);
        $this->assertTrue($run->canTransitionTo('exported'));
        $this->assertFalse($run->canTransitionTo('draft'));
    }

    public function test_invalid_state_transition_throws(): void
    {
        $run = $this->createDraftRun();
        $this->expectException(\DomainException::class);
        $run->transitionTo('exported');
    }

    // ══════════════════════════════════════════════════════════
    // CROSS-COMPANY ISOLATION
    // ══════════════════════════════════════════════════════════

    public function test_cannot_access_other_company_run(): void
    {
        Model::unguard();
        $other = Company::create([
            'name' => 'Other Co', 'slug' => 'other-co-w5',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR',
        ]);
        $otherRun = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'status' => 'draft', 'currency' => 'EUR',
            'idempotency_key' => 'payroll:other:2026-05-01:2026-05-31',
        ]);
        Model::reguard();

        $this->apiGet("/api/workforce/payroll/{$otherRun->id}")->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════

    private function createDraftRun(string $start = '2026-05-01', string $end = '2026-05-31'): PayrollRun
    {
        Model::unguard();
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => $start, 'period_end' => $end,
            'status' => 'draft', 'currency' => 'EUR',
            'idempotency_key' => "payroll:{$this->company->id}:{$start}:{$end}",
        ]);
        Model::reguard();

        return $run;
    }

    private function createRunWithLine(): PayrollRun
    {
        $run = $this->createDraftRun();

        Model::unguard();
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'status' => 'locked',
        ]);

        PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $period->id,
            'worked_minutes' => 9100,
            'break_minutes' => 1300,
            'total_overtime_minutes' => 0,
            'base_salary_cents' => 280000,
            'gross_basis_cents' => 280000,
            'gross_breakdown' => json_encode([]),
            'compensation_snapshot' => json_encode([]),
            'timesheet_snapshot' => json_encode([]),
            'anomalies' => json_encode([]),
        ]);
        Model::reguard();

        return $run;
    }
}
