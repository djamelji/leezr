<?php

namespace Tests\Feature;

use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\BulletinGroup;
use App\Core\Workforce\DTOs\ContributionLine;
use App\Core\Workforce\Employee;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\Services\ContributionCalculator;
use App\Core\Workforce\Services\PayrollRuleResolver;
use App\Core\Workforce\TimesheetPeriod;
use Carbon\Carbon;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 5.3 — Official FR payslip variable resolver.
 * Tests: bulletin_group propagation, net_social, relief, SIRET/NAF, YTD, grouped variables.
 */
class PayslipOfficialResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR',
            'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000,
            'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    // ──────────────────────────────────────
    // T1: bulletin_group propagated through ContributionLine
    // ──────────────────────────────────────
    public function test_T1_bulletin_group_propagated_in_contribution_line(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $result = ContributionCalculator::compute(350000, $plafond, $csgMultiplier, $rules);

        // Every line should now have bulletin_group
        foreach ($result->lines as $line) {
            $this->assertNotNull($line->bulletinGroup, "Line {$line->code} should have bulletinGroup");
            $this->assertTrue(
                BulletinGroup::isValid($line->bulletinGroup),
                "Line {$line->code} has invalid bulletinGroup: {$line->bulletinGroup}"
            );
        }

        // All 7 groups covered
        $groups = collect($result->lines)->pluck('bulletinGroup')->unique()->sort()->values()->toArray();
        $this->assertCount(7, $groups, 'All 7 bulletin groups should be covered');
    }

    // ──────────────────────────────────────
    // T2: bulletin_group in ContributionLine::toArray()
    // ──────────────────────────────────────
    public function test_T2_bulletin_group_in_to_array(): void
    {
        $line = new ContributionLine(
            code: 'test',
            label: 'Test',
            category: 'urssaf',
            baseType: 'deplafonne',
            baseCents: 100000,
            employeeRateBps: 100,
            employerRateBps: 200,
            employeeCents: 1000,
            employerCents: 2000,
            bulletinGroup: 'sante',
        );

        $arr = $line->toArray();
        $this->assertEquals('sante', $arr['bulletin_group']);
    }

    // ──────────────────────────────────────
    // T3: ContributionLine backward compat (no bulletin_group)
    // ──────────────────────────────────────
    public function test_T3_contribution_line_backward_compat(): void
    {
        $line = new ContributionLine(
            code: 'test',
            label: 'Test',
            category: 'urssaf',
            baseType: 'deplafonne',
            baseCents: 100000,
            employeeRateBps: 100,
            employerRateBps: 200,
            employeeCents: 1000,
            employerCents: 2000,
        );

        $this->assertNull($line->bulletinGroup);
        $arr = $line->toArray();
        $this->assertNull($arr['bulletin_group']);
    }

    // ──────────────────────────────────────
    // T4: company.siret and company.naf_code in resolver
    // ──────────────────────────────────────
    public function test_T4_company_siret_naf_in_resolver(): void
    {
        $company = Company::create([
            'name' => 'SIRET Test', 'slug' => 'siret-test',
            'jobdomain_key' => 'logistique',
            'siret' => '12345678901234',
            'naf_code' => '6201Z',
        ]);

        // Use a fake employee-type resolver to test company variables
        $vars = DocumentVariableResolver::resolve('payroll_run', PayrollRun::create([
            'company_id' => $company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'employee_count' => 1,
            'status' => 'draft',
            'currency' => 'EUR',
        ]), $company);

        $this->assertEquals('12345678901234', $vars['company.siret']);
        $this->assertEquals('6201Z', $vars['company.naf_code']);
    }

    // ──────────────────────────────────────
    // T5: net_social_formatted in resolver
    // ──────────────────────────────────────
    public function test_T5_net_social_in_resolver_variables(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $company = Company::create([
            'name' => 'NS Resolver Test', 'slug' => 'ns-resolver',
            'jobdomain_key' => 'logistique',
        ]);

        // Create minimal payroll chain
        $run = PayrollRun::create([
            'company_id' => $company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'employee_count' => 1,
            'status' => 'validated',
            'currency' => 'EUR',
        ]);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));
        $contributions = ContributionCalculator::compute(350000, $plafond, $csgMultiplier, $rules);

        $line = $this->createMinimalPayrollLine($run, $company);

        // Create calculation with net_social_cents
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'payroll_line_id' => $line->id,
            'rule_version' => 'test-v1',
            'gross_total_cents' => 350000,
            'plafond_ss_monthly_cents' => $plafond,
            'contributions_employee_cents' => $contributions->totalEmployeeCents,
            'contributions_employer_cents' => $contributions->totalEmployerCents,
            'total_cost_employer_cents' => 350000 + $contributions->totalEmployerCents,
            'taxable_income_cents' => 300000,
            'tax_cents' => 30000,
            'net_before_tax_cents' => 270000,
            'net_payable_cents' => 240000,
            'net_social_cents' => 302432,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => $contributions->toArray(),
            'tax_breakdown' => ['tax_rate_bps' => 1000],
            'benefit_lines' => [],
            'deduction_lines' => [],
            'relief_lines' => ['total_employer_relief_cents' => 0, 'lines' => []],
            'calculation_snapshot' => [],
            'status' => 'validated',
            'calculated_at' => now(),
        ]);

        $vars = DocumentVariableResolver::resolve('payroll_line', $line->fresh(), $company);

        // net_social variables
        $this->assertArrayHasKey('calculation.net_social_formatted', $vars);
        $this->assertArrayHasKey('calculation.net_social_cents', $vars);
        $this->assertStringContainsString('3 024,32', $vars['calculation.net_social_formatted']);

        // relief variables
        $this->assertArrayHasKey('calculation.relief_total_formatted', $vars);
    }

    // ──────────────────────────────────────
    // T6: bulletin_group.* variables present in resolver
    // ──────────────────────────────────────
    public function test_T6_bulletin_group_variables_in_resolver(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $company = Company::create([
            'name' => 'BG Resolver Test', 'slug' => 'bg-resolver',
            'jobdomain_key' => 'logistique',
        ]);

        $run = PayrollRun::create([
            'company_id' => $company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'employee_count' => 1,
            'status' => 'validated',
            'currency' => 'EUR',
        ]);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));
        $contributions = ContributionCalculator::compute(350000, $plafond, $csgMultiplier, $rules);

        $line = $this->createMinimalPayrollLine($run, $company);

        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'payroll_line_id' => $line->id,
            'rule_version' => 'test-v1',
            'gross_total_cents' => 350000,
            'plafond_ss_monthly_cents' => $plafond,
            'contributions_employee_cents' => $contributions->totalEmployeeCents,
            'contributions_employer_cents' => $contributions->totalEmployerCents,
            'total_cost_employer_cents' => 350000 + $contributions->totalEmployerCents,
            'taxable_income_cents' => 300000,
            'tax_cents' => 30000,
            'net_before_tax_cents' => 270000,
            'net_payable_cents' => 240000,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => $contributions->toArray(),
            'tax_breakdown' => ['tax_rate_bps' => 1000],
            'benefit_lines' => [],
            'deduction_lines' => [],
            'calculation_snapshot' => [],
            'status' => 'validated',
            'calculated_at' => now(),
        ]);

        $vars = DocumentVariableResolver::resolve('payroll_line', $line->fresh(), $company);

        // All 7 bulletin groups must have variables
        foreach (BulletinGroup::DISPLAY_ORDER as $group) {
            $this->assertArrayHasKey("bulletin_group.{$group}.label", $vars, "Missing label for group {$group}");
            $this->assertArrayHasKey("bulletin_group.{$group}.employee_total_formatted", $vars);
            $this->assertArrayHasKey("bulletin_group.{$group}.employer_total_formatted", $vars);
            $this->assertArrayHasKey("bulletin_group.{$group}.line_count", $vars);
            $this->assertArrayHasKey("bulletin_group.{$group}.line_1_label", $vars);
        }

        // Sante group should have non-empty label
        $this->assertEquals('Santé', $vars['bulletin_group.sante.label']);
        $this->assertNotEmpty($vars['bulletin_group.sante.employer_total_formatted']);

        // Retraite should have multiple lines
        $this->assertGreaterThanOrEqual(3, (int) $vars['bulletin_group.retraite.line_count']);
    }

    // ──────────────────────────────────────
    // T7: Resolver version bumped to v2
    // ──────────────────────────────────────
    public function test_T7_resolver_version_bumped(): void
    {
        $this->assertEquals('v2', DocumentVariableResolver::RESOLVER_VERSION);
    }

    // ──────────────────────────────────────
    // T8: Template seeder produces valid template
    // ──────────────────────────────────────
    public function test_T8_official_template_seeder(): void
    {
        $this->seed(\Database\Seeders\PayslipOfficialFrTemplateSeeder::class);

        $template = \App\Core\Documents\DocumentTemplate::where('code', 'payslip_official_fr')
            ->whereNull('company_id')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($template);
        $this->assertEquals('payroll_line', $template->subject_type);
        $this->assertEquals('a4', $template->paper_size);
        $this->assertTrue($template->is_system);

        // Body should contain bulletin group variables
        $this->assertStringContainsString('bulletin_group.sante.label', $template->body_html);
        $this->assertStringContainsString('bulletin_group.retraite.label', $template->body_html);
        $this->assertStringContainsString('calculation.net_social_formatted', $template->body_html);
        $this->assertStringContainsString('BULLETIN DE PAIE', $template->body_html);

        // Should NOT contain BROUILLON
        $this->assertStringNotContainsString('BROUILLON', $template->body_html);
    }

    // ──────────────────────────────────────
    // T9: Template seeder is idempotent
    // ──────────────────────────────────────
    public function test_T9_official_template_seeder_idempotent(): void
    {
        $this->seed(\Database\Seeders\PayslipOfficialFrTemplateSeeder::class);
        $count1 = \App\Core\Documents\DocumentTemplate::where('code', 'payslip_official_fr')->count();

        $this->seed(\Database\Seeders\PayslipOfficialFrTemplateSeeder::class);
        $count2 = \App\Core\Documents\DocumentTemplate::where('code', 'payslip_official_fr')->count();

        $this->assertEquals($count1, $count2);
    }

    // ──────────────────────────────────────
    // T10: YTD variables present (empty when no snapshot)
    // ──────────────────────────────────────
    public function test_T10_ytd_variables_present_empty(): void
    {
        $company = Company::create([
            'name' => 'YTD Test', 'slug' => 'ytd-test',
            'jobdomain_key' => 'logistique',
        ]);

        $run = PayrollRun::create([
            'company_id' => $company->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'employee_count' => 1,
            'status' => 'validated',
            'currency' => 'EUR',
        ]);

        $line = $this->createMinimalPayrollLine($run, $company);

        $vars = DocumentVariableResolver::resolve('payroll_line', $line->fresh(), $company);

        // YTD variables should exist (empty since no snapshot)
        $this->assertArrayHasKey('ytd.gross_total_formatted', $vars);
        $this->assertArrayHasKey('ytd.contributions_employee_formatted', $vars);
        $this->assertArrayHasKey('ytd.tax_formatted', $vars);
        $this->assertArrayHasKey('ytd.net_payable_formatted', $vars);
        $this->assertArrayHasKey('ytd.months_count', $vars);
    }

    // ──────────────────────────────────────
    // Helper: create minimal PayrollLine with required NOT NULL fields
    // ──────────────────────────────────────
    private function createMinimalPayrollLine(PayrollRun $run, Company $company): PayrollLine
    {
        $user = User::factory()->create();
        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'employee_number' => 'EMP-' . uniqid(),
            'email' => 'jean' . uniqid() . '@test.com',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        $tp = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_start' => $run->period_start,
            'period_end' => $run->period_end,
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $user->id,
        ]);

        return PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'payroll_run_id' => $run->id,
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
            'base_salary_cents' => 350000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 350000,
            'gross_breakdown' => ['base_salary_cents' => 350000, 'overtime_cents' => 0, 'gross_basis_cents' => 350000],
            'compensation_snapshot' => ['base_salary_cents' => 350000, 'currency' => 'EUR', 'pay_frequency' => 'monthly'],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);
    }
}
