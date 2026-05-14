<?php

namespace Tests\Feature;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\Services\DocumentTemplateResolver;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\DeletePayrollRunUseCase;
use App\Modules\Workforce\UseCases\ExportPayrollUseCase;
use App\Modules\Workforce\UseCases\GeneratePayslipDraftsUseCase;
use App\Modules\Workforce\UseCases\RecomputePayrollUseCase;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollUseCaseTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;
    protected Employee $employee;
    protected EmploymentContract $contract;
    protected PayrollRun $run;
    protected PayrollLine $line;
    protected TimesheetPeriod $timesheet;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        // Market + rules
        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris',
            'vat_rate_bps' => 2000,
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '',
        ]);
        $this->seed(WorkforcePayrollRuleSeeder::class);

        // Enable modules for UseCases that check ModuleGate
        PlatformModule::create([
            'key' => 'workforce_payroll',
            'name' => 'Payroll',
            'is_enabled_globally' => true,
        ]);
        PlatformModule::create([
            'key' => 'workforce_documents',
            'name' => 'Documents',
            'is_enabled_globally' => true,
        ]);

        // Company + User
        $this->company = Company::create([
            'name' => 'Test UseCase Co',
            'slug' => 'test-usecase-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->user = User::factory()->create();

        // Employee chain
        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'email' => 'marie@test.com',
            'employee_number' => 'EMP-UC-001',
            'hire_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $this->contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2025-01-01',
            'is_current' => true,
        ]);
        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => 350000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2025-01-01',
        ]);

        // Locked timesheet
        $this->timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        // Default run in COMPUTED status with 1 line
        $this->run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 350000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:' . time() . ':unique',
        ]);

        $this->line = $this->createLineForRun($this->run);

        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // --- Helpers ---

    private function createRunWithStatus(string $status, ?string $suffix = null): PayrollRun
    {
        $s = $suffix ?? uniqid();

        return PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => $status,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 350000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => "payroll:{$this->company->id}:2026-06-01:2026-06-30:{$s}",
        ]);
    }

    private function createLineForRun(PayrollRun $run): PayrollLine
    {
        return PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee->id,
            'timesheet_period_id' => $this->timesheet->id,
            'worked_minutes' => 9100,
            'break_minutes' => 0,
            'daily_overtime_minutes' => 0,
            'weekly_overtime_minutes' => 0,
            'total_overtime_minutes' => 0,
            'planned_minutes' => 9100,
            'leave_days_hundredths' => 0,
            'paid_leave_days_hundredths' => 0,
            'unpaid_leave_days_hundredths' => 0,
            'leave_minutes' => 0,
            'base_salary_cents' => 350000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 350000,
            'gross_breakdown' => [
                'base_salary_cents' => 350000,
                'overtime_cents' => 0,
                'unpaid_leave_deduction_cents' => 0,
                'gross_basis_cents' => 350000,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => 350000,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'benefits' => [],
            ],
            'timesheet_snapshot' => [
                'total_worked_minutes' => 9100,
            ],
        ]);
    }

    private function createCalculationForLine(PayrollLine $line, string $status = 'validated'): PayrollCalculation
    {
        return PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $line->id,
            'status' => $status,
            'rule_version' => 'payroll-calc-v1',
            'gross_total_cents' => 350000,
            'plafond_ss_monthly_cents' => 383400,
            'contribution_lines' => [
                ['code' => 'SANTE', 'label' => 'Santé', 'base_cents' => 350000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 700, 'employee_cents' => 0, 'employer_cents' => 2450],
                ['code' => 'VIEILLESSE_P', 'label' => 'Vieillesse plafonnée', 'base_cents' => 350000, 'employee_rate_bps' => 690, 'employer_rate_bps' => 855, 'employee_cents' => 2415, 'employer_cents' => 2993],
                ['code' => 'CSG_CRDS', 'label' => 'CSG/CRDS', 'base_cents' => 339850, 'employee_rate_bps' => 970, 'employer_rate_bps' => 0, 'employee_cents' => 3297, 'employer_cents' => 0],
            ],
            'contributions_employee_cents' => 78050,
            'contributions_employer_cents' => 136850,
            'total_cost_employer_cents' => 486850,
            'taxable_income_cents' => 271950,
            'tax_breakdown' => ['rate_bps' => 1100, 'brackets' => []],
            'tax_cents' => 29915,
            'net_before_tax_cents' => 271950,
            'net_payable_cents' => 242035,
            'deductions_cents' => 0,
            'benefits_cents' => 0,
            'blocking_anomalies' => [],
            'calculation_snapshot' => [
                'gross_breakdown' => ['base_salary_cents' => 350000],
                'contribution_lines_count' => 3,
                'rule_version' => 'payroll-calc-v1',
            ],
            'calculated_at' => now(),
            'calculated_by' => 1,
        ]);
    }

    // =============================================
    // RECOMPUTE PAYROLL USE CASE (7 tests)
    // =============================================

    /** @test */
    public function recompute_happy_path_transitions_and_recomputes()
    {
        $oldLineId = $this->line->id;

        $useCase = app(RecomputePayrollUseCase::class);
        $result = $useCase->execute($this->run, $this->user->id);

        // Run should be back to COMPUTED after recompute
        $this->assertEquals(PayrollRun::STATUS_COMPUTED, $result->status);

        // Old line should be deleted, new line created
        $this->assertNull(PayrollLine::withoutGlobalScopes()->find($oldLineId));
        $newLines = PayrollLine::withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->get();
        $this->assertCount(1, $newLines);
        $this->assertNotEquals($oldLineId, $newLines->first()->id);
    }

    /** @test */
    public function recompute_blocks_draft_status()
    {
        $draftRun = $this->createRunWithStatus(PayrollRun::STATUS_DRAFT, 'draft');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'computed'");

        app(RecomputePayrollUseCase::class)->execute($draftRun, $this->user->id);
    }

    /** @test */
    public function recompute_blocks_validated_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'val');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'computed'");

        app(RecomputePayrollUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function recompute_blocks_exported_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_EXPORTED, 'exp');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'computed'");

        app(RecomputePayrollUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function recompute_creates_audit_log()
    {
        app(RecomputePayrollUseCase::class)->execute($this->run, $this->user->id);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'payroll_run.recompute_initiated',
            'target_type' => 'payroll_run',
            'target_id' => (string) $this->run->id,
        ]);
    }

    /** @test */
    public function recompute_preserves_run_identity()
    {
        $runId = $this->run->id;
        $result = app(RecomputePayrollUseCase::class)->execute($this->run, $this->user->id);

        // Same run, not a new one
        $this->assertEquals($runId, $result->id);
    }

    /** @test */
    public function recompute_updates_computed_metadata()
    {
        $result = app(RecomputePayrollUseCase::class)->execute($this->run, $this->user->id);

        // Computed metadata should be set (by the internal ComputePayrollUseCase call)
        $this->assertEquals($this->user->id, $result->computed_by);
        $this->assertNotNull($result->computed_at);
    }

    // =============================================
    // EXPORT PAYROLL USE CASE (7 tests)
    // =============================================

    /** @test */
    public function export_happy_path_transitions_to_exported()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'export-hp');
        $line = $this->createLineForRun($run);

        $result = app(ExportPayrollUseCase::class)->execute($run, $this->user->id);

        $this->assertEquals(PayrollRun::STATUS_EXPORTED, $result->status);
        $this->assertEquals($this->user->id, $result->exported_by);
        $this->assertNotNull($result->exported_at);
        $this->assertEquals('json', $result->export_format);
    }

    /** @test */
    public function export_blocks_draft_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_DRAFT, 'exp-draft');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'validated'");

        app(ExportPayrollUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function export_blocks_computed_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_COMPUTED, 'exp-comp');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'validated'");

        app(ExportPayrollUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function export_blocks_already_exported_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_EXPORTED, 'exp-exp');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'validated'");

        app(ExportPayrollUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function export_builds_line_payloads()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'exp-payload');
        $line = $this->createLineForRun($run);

        app(ExportPayrollUseCase::class)->execute($run, $this->user->id);

        // Reload line from DB
        $updatedLine = PayrollLine::withoutGlobalScopes()->find($line->id);
        $payload = $updatedLine->export_payload;

        $this->assertNotNull($payload);
        $this->assertEquals($this->employee->id, $payload['employee_id']);
        $this->assertEquals('EMP-UC-001', $payload['employee_number']);
        $this->assertEquals(350000, $payload['gross_basis_cents']);
        $this->assertEquals(PayrollLine::FORMULA_VERSION, $payload['formula_version']);
    }

    /** @test */
    public function export_builds_exported_snapshot()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'exp-snap');
        $this->createLineForRun($run);

        $result = app(ExportPayrollUseCase::class)->execute($run, $this->user->id);

        $snapshot = $result->exported_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertEquals('json', $snapshot['format']);
        $this->assertArrayHasKey('exported_at', $snapshot);
        $this->assertArrayHasKey('lines', $snapshot);
        $this->assertCount(1, $snapshot['lines']);
    }

    /** @test */
    public function export_creates_audit_log()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'exp-audit');
        $this->createLineForRun($run);

        app(ExportPayrollUseCase::class)->execute($run, $this->user->id);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'payroll_run.exported',
            'target_type' => 'payroll_run',
            'target_id' => (string) $run->id,
        ]);
    }

    // =============================================
    // DELETE PAYROLL USE CASE (6 tests)
    // =============================================

    /** @test */
    public function delete_happy_path_removes_draft_run()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_DRAFT, 'del-hp');
        $runId = $run->id;

        app(DeletePayrollRunUseCase::class)->execute($run, $this->user->id);

        $this->assertNull(PayrollRun::withoutGlobalScopes()->find($runId));
    }

    /** @test */
    public function delete_blocks_computed_status()
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'draft'");

        app(DeletePayrollRunUseCase::class)->execute($this->run, $this->user->id);
    }

    /** @test */
    public function delete_blocks_validated_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'del-val');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'draft'");

        app(DeletePayrollRunUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function delete_blocks_exported_status()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_EXPORTED, 'del-exp');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'draft'");

        app(DeletePayrollRunUseCase::class)->execute($run, $this->user->id);
    }

    /** @test */
    public function delete_cascades_lines()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_DRAFT, 'del-cascade');
        $line = $this->createLineForRun($run);
        $lineId = $line->id;

        app(DeletePayrollRunUseCase::class)->execute($run, $this->user->id);

        $this->assertNull(PayrollLine::withoutGlobalScopes()->find($lineId));
    }

    /** @test */
    public function delete_creates_audit_log()
    {
        $run = $this->createRunWithStatus(PayrollRun::STATUS_DRAFT, 'del-audit');
        $runId = $run->id;

        app(DeletePayrollRunUseCase::class)->execute($run, $this->user->id);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => 'payroll_run.deleted',
            'target_type' => 'payroll_run',
            'target_id' => (string) $runId,
        ]);
    }

    // =============================================
    // CALCULATION INVARIANTS (6 tests)
    // =============================================

    /** @test */
    public function calculation_net_before_tax_equals_gross_minus_employee_contributions()
    {
        $calc = $this->createCalculationForLine($this->line);

        $this->assertEquals(
            $calc->gross_total_cents - $calc->contributions_employee_cents,
            $calc->net_before_tax_cents,
            'net_before_tax must equal gross_total - contributions_employee'
        );
    }

    /** @test */
    public function calculation_net_payable_equals_net_before_tax_minus_tax()
    {
        $calc = $this->createCalculationForLine($this->line);

        $this->assertEquals(
            $calc->net_before_tax_cents - $calc->tax_cents,
            $calc->net_payable_cents,
            'net_payable must equal net_before_tax - tax'
        );
    }

    /** @test */
    public function calculation_employer_cost_equals_gross_plus_employer_contributions()
    {
        $calc = $this->createCalculationForLine($this->line);

        $this->assertEquals(
            $calc->gross_total_cents + $calc->contributions_employer_cents,
            $calc->total_cost_employer_cents,
            'employer cost must equal gross + employer contributions'
        );
    }

    /** @test */
    public function calculation_all_monetary_fields_non_negative()
    {
        $calc = $this->createCalculationForLine($this->line);

        $monetaryFields = [
            'gross_total_cents',
            'contributions_employee_cents',
            'contributions_employer_cents',
            'total_cost_employer_cents',
            'taxable_income_cents',
            'tax_cents',
            'net_before_tax_cents',
            'net_payable_cents',
        ];

        foreach ($monetaryFields as $field) {
            $this->assertGreaterThanOrEqual(0, $calc->$field, "Field {$field} must be >= 0");
        }
    }

    /** @test */
    public function validated_calculation_is_immutable()
    {
        $calc = $this->createCalculationForLine($this->line, 'validated');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('validated and immutable');

        $calc->gross_total_cents = 999999;
        $calc->save();
    }

    /** @test */
    public function calculated_calculation_allows_updates()
    {
        $calc = $this->createCalculationForLine($this->line, 'calculated');

        $calc->gross_total_cents = 400000;
        $calc->save();

        $this->assertEquals(400000, $calc->fresh()->gross_total_cents);
    }

    // =============================================
    // TEMPLATE / PDF FAILURE (3 tests)
    // =============================================

    /** @test */
    public function template_resolver_throws_when_template_missing()
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No active template found');

        DocumentTemplateResolver::resolve($this->company->id, 'nonexistent_template_code');
    }

    /** @test */
    public function template_resolver_exception_includes_code_and_company()
    {
        try {
            DocumentTemplateResolver::resolve($this->company->id, 'missing_template');
            $this->fail('Expected DomainException was not thrown');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('missing_template', $e->getMessage());
            $this->assertStringContainsString((string) $this->company->id, $e->getMessage());
        }
    }

    /** @test */
    public function payslip_draft_generation_fails_without_template()
    {
        // Create validated run with validated calculation (all guards pass)
        $run = $this->createRunWithStatus(PayrollRun::STATUS_VALIDATED, 'tpl-missing');
        $line = $this->createLineForRun($run);
        $this->createCalculationForLine($line, 'validated');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No active template found');

        app(GeneratePayslipDraftsUseCase::class)->execute($run, $this->user->id);
    }
}
