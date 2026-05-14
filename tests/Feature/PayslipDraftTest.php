<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use App\Modules\Workforce\UseCases\GeneratePayslipDraftsUseCase;
use App\Modules\Workforce\UseCases\ValidatePayrollUseCase;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayslipDraftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PayrollRun $run;
    private PayrollLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        // Market (FK for MarketRuleSet)
        \App\Core\Markets\Market::create([
            'key' => 'FR',
            'name' => 'France',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '🇫🇷',
            'vat_rate_bps' => 2000,
        ]);

        $this->user = User::factory()->create();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'TestCo',
            'slug' => 'testco',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'tech',
        ]);

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'employee_number' => 'EMP-001',
            'email' => 'jean@test.com',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'start_date' => '2024-01-15',
            'status' => EmploymentContract::STATUS_ACTIVE,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $contract->id,
            'base_salary_cents' => 300000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'effective_from' => '2024-01-15',
        ]);

        $tp = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
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

        $this->run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
        ]);

        $this->line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->run->id,
            'employee_id' => $employee->id,
            'timesheet_period_id' => $tp->id,
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
            'base_salary_cents' => 300000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 300000,
            'gross_breakdown' => [
                'base_salary_cents' => 300000,
                'overtime_cents' => 0,
                'unpaid_leave_deduction_cents' => 0,
                'gross_basis_cents' => 300000,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => 300000,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'benefits' => [],
            ],
            'timesheet_snapshot' => [
                'total_worked_minutes' => 9100,
            ],
        ]);

        // Seed payroll rules
        $this->seed(\Database\Seeders\WorkforcePayrollRuleSeeder::class);

        // Seed payslip template
        $this->seed(\Database\Seeders\PayslipDraftTemplateSeeder::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        parent::tearDown();
    }

    private function computeAndValidate(): void
    {
        // Compute calculations
        app(ComputePayrollCalculationsUseCase::class)->execute($this->run, $this->user->id);
        $this->run->refresh();

        // Validate
        app(ValidatePayrollUseCase::class)->execute($this->run, $this->user->id);
        $this->run->refresh();
    }

    // --- T1: Resolver enrichit payroll_line avec calculation.* ---

    /** @test */
    public function resolver_enriches_payroll_line_with_calculation_variables()
    {
        // Create a calculation for the line
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $this->line->id,
            'rule_version' => 'payroll-calc-v1',
            'plafond_ss_monthly_cents' => 383400,
            'gross_total_cents' => 300000,
            'contributions_employee_cents' => 66000,
            'contributions_employer_cents' => 126000,
            'total_cost_employer_cents' => 426000,
            'taxable_income_cents' => 240000,
            'tax_cents' => 24000,
            'net_before_tax_cents' => 234000,
            'net_payable_cents' => 210000,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => [
                ['code' => 'urssaf_maladie', 'label' => 'Maladie', 'base_cents' => 300000, 'employee_rate_bps' => 50, 'employer_rate_bps' => 700, 'employee_cents' => 1500, 'employer_cents' => 21000],
            ],
            'tax_breakdown' => ['tax_rate_bps' => 1000, 'taxable_income_cents' => 240000, 'tax_cents' => 24000],
            'benefit_lines' => [],
            'deduction_lines' => [],
            'blocking_anomalies' => null,
            'calculation_snapshot' => [],
            'status' => PayrollCalculation::STATUS_CALCULATED,
            'calculated_at' => now(),
            'calculated_by' => $this->user->id,
        ]);

        // Reload line with calculation
        $this->line->refresh();

        $vars = DocumentVariableResolver::resolve('payroll_line', $this->line, $this->company);

        $this->assertArrayHasKey('calculation.gross_total_formatted', $vars);
        $this->assertArrayHasKey('calculation.net_payable_formatted', $vars);
        $this->assertArrayHasKey('calculation.contributions_employee_formatted', $vars);
        $this->assertArrayHasKey('calculation.tax_formatted', $vars);
        $this->assertArrayHasKey('calculation.tax_rate_percent', $vars);
        $this->assertArrayHasKey('calculation.contribution_line_1_code', $vars);
        $this->assertEquals('urssaf_maladie', $vars['calculation.contribution_line_1_code']);
        $this->assertStringContainsString('10.00%', $vars['calculation.tax_rate_percent']);
        $this->assertEquals('1', $vars['calculation.contribution_line_count']);
    }

    // --- T2: Resolver payroll_line sans calculation → vars vides ---

    /** @test */
    public function resolver_returns_empty_calculation_vars_without_calculation()
    {
        $vars = DocumentVariableResolver::resolve('payroll_line', $this->line, $this->company);

        $this->assertArrayHasKey('calculation.gross_total_formatted', $vars);
        $this->assertEquals('', $vars['calculation.gross_total_formatted']);
        $this->assertEquals('', $vars['calculation.net_payable_formatted']);
        $this->assertEquals('', $vars['calculation.tax_rate_percent']);
        $this->assertEquals('0', $vars['calculation.contribution_line_count']);
    }

    // --- T3: Contribution lines indexées correctement ---

    /** @test */
    public function resolver_indexes_contribution_lines_correctly()
    {
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $this->line->id,
            'rule_version' => 'payroll-calc-v1',
            'plafond_ss_monthly_cents' => 383400,
            'gross_total_cents' => 300000,
            'contributions_employee_cents' => 66000,
            'contributions_employer_cents' => 126000,
            'total_cost_employer_cents' => 426000,
            'taxable_income_cents' => 240000,
            'tax_cents' => 24000,
            'net_before_tax_cents' => 234000,
            'net_payable_cents' => 210000,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => [
                ['code' => 'first', 'label' => 'First', 'base_cents' => 100, 'employee_rate_bps' => 50, 'employer_rate_bps' => 100, 'employee_cents' => 5, 'employer_cents' => 10],
                ['code' => 'second', 'label' => 'Second', 'base_cents' => 200, 'employee_rate_bps' => 75, 'employer_rate_bps' => 150, 'employee_cents' => 15, 'employer_cents' => 30],
                ['code' => 'third', 'label' => 'Third', 'base_cents' => 300, 'employee_rate_bps' => 100, 'employer_rate_bps' => 200, 'employee_cents' => 30, 'employer_cents' => 60],
            ],
            'tax_breakdown' => ['tax_rate_bps' => 500],
            'benefit_lines' => [],
            'deduction_lines' => [],
            'blocking_anomalies' => null,
            'calculation_snapshot' => [],
            'status' => PayrollCalculation::STATUS_CALCULATED,
            'calculated_at' => now(),
        ]);

        $this->line->refresh();
        $vars = DocumentVariableResolver::resolve('payroll_line', $this->line, $this->company);

        $this->assertEquals('first', $vars['calculation.contribution_line_1_code']);
        $this->assertEquals('Second', $vars['calculation.contribution_line_2_label']);
        $this->assertEquals('third', $vars['calculation.contribution_line_3_code']);
        $this->assertEquals('3', $vars['calculation.contribution_line_count']);
        // Slot 4 exists but is empty (padded)
        $this->assertEquals('', $vars['calculation.contribution_line_4_code']);
    }

    // --- T4: Happy path — generates documents for all lines ---

    /** @test */
    public function generate_payslip_drafts_happy_path()
    {
        $this->computeAndValidate();

        $docs = app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);

        $this->assertCount(1, $docs);
        $this->assertInstanceOf(GeneratedDocument::class, $docs[0]);
        $this->assertEquals('payroll_line', $docs[0]->subject_type);
        $this->assertEquals($this->line->id, $docs[0]->subject_id);
        $this->assertEquals($this->company->id, $docs[0]->company_id);
    }

    // --- T5: Blocks non-validated run ---

    /** @test */
    public function generate_payslip_drafts_blocks_non_validated_run()
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'validated'");

        app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T6: Blocks if blocking anomalies ---

    /** @test */
    public function generate_payslip_drafts_blocks_with_blocking_anomalies()
    {
        // Compute to get calculations
        app(ComputePayrollCalculationsUseCase::class)->execute($this->run, $this->user->id);

        // Manually set blocking anomalies + validated status on run
        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();
        $calc->blocking_anomalies = [['type' => 'negative_net', 'severity' => 'blocking']];
        $calc->status = PayrollCalculation::STATUS_VALIDATED;
        $calc->save();

        $this->run->status = PayrollRun::STATUS_VALIDATED;
        $this->run->validated_by = $this->user->id;
        $this->run->validated_at = now();
        $this->run->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('blocking anomalies');

        app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T7: Idempotent — re-run returns existing ---

    /** @test */
    public function generate_payslip_drafts_is_idempotent()
    {
        $this->computeAndValidate();

        $docs1 = app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);
        $docs2 = app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);

        $this->assertCount(1, $docs1);
        $this->assertCount(1, $docs2);
        $this->assertEquals($docs1[0]->id, $docs2[0]->id);

        // Only 1 document in DB
        $count = GeneratedDocument::withoutGlobalScopes()
            ->where('subject_type', 'payroll_line')
            ->where('subject_id', $this->line->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    // --- T8: Snapshot contains calculation data ---

    /** @test */
    public function generation_snapshot_includes_calculation_data()
    {
        $this->computeAndValidate();

        $docs = app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);
        $snapshot = $docs[0]->generation_snapshot;

        $this->assertArrayHasKey('calculation.gross_total_formatted', $snapshot);
        $this->assertArrayHasKey('calculation.net_payable_formatted', $snapshot);
        $this->assertArrayHasKey('calculation.contributions_employee_formatted', $snapshot);
        $this->assertNotEmpty($snapshot['calculation.gross_total_formatted']);
    }

    // --- T9: Template contains disclaimer ---

    /** @test */
    public function seeded_template_contains_disclaimer()
    {
        $template = DocumentTemplate::where('code', 'payslip_draft_fr')
            ->where('company_id', null)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($template);
        $this->assertStringContainsString('BROUILLON', $template->body_html);
        $this->assertStringContainsString('BROUILLON', $template->footer_html);
        $this->assertStringContainsString('indicatif', $template->footer_html);
        $this->assertEquals('payroll_line', $template->subject_type);
    }

    // --- T10: Multi-tenant isolation ---

    /** @test */
    public function multi_tenant_payslip_generation_isolated()
    {
        $this->computeAndValidate();

        // Generate for company A
        $docsA = app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);
        $this->assertCount(1, $docsA);

        // Company B has no docs
        $companyB = Company::withoutGlobalScopes()->create([
            'name' => 'OtherCo',
            'slug' => 'otherco',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'finance',
        ]);

        $docsB = GeneratedDocumentReadModel::forPayrollRun($this->run->id, $companyB->id);
        $this->assertCount(0, $docsB);
    }

    // --- T11: PayrollLine.payslipDraft() relationship ---

    /** @test */
    public function payroll_line_payslip_draft_relationship()
    {
        $this->computeAndValidate();

        app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);

        $this->line->refresh();
        $draft = $this->line->payslipDraft;

        $this->assertNotNull($draft);
        $this->assertInstanceOf(GeneratedDocument::class, $draft);
        $this->assertEquals('payroll_line', $draft->subject_type);
    }

    // --- T12: forPayrollRun readmodel ---

    /** @test */
    public function readmodel_for_payroll_run_returns_payslips()
    {
        $this->computeAndValidate();

        app(GeneratePayslipDraftsUseCase::class)->execute($this->run, $this->user->id);

        $docs = GeneratedDocumentReadModel::forPayrollRun($this->run->id, $this->company->id);

        $this->assertCount(1, $docs);
        $this->assertEquals('payroll_line', $docs->first()->subject_type);
        $this->assertEquals($this->line->id, $docs->first()->subject_id);
    }
}
