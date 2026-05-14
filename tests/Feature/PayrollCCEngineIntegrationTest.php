<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompanyPayrollRuleOverride;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollCalculationEngine;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use App\Modules\Workforce\UseCases\ExportPayrollUseCase;
use App\Modules\Workforce\UseCases\RecomputePayrollUseCase;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCCEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;
    protected Employee $employee;
    protected EmploymentContract $contract;
    protected ConventionCollective $cc;
    protected PayrollRun $run;
    protected PayrollLine $line;
    protected TimesheetPeriod $timesheet;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

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

        PlatformModule::create([
            'key' => 'workforce_payroll',
            'name' => 'Payroll',
            'is_enabled_globally' => true,
        ]);

        $this->company = Company::create([
            'name' => 'CC Engine Test Co',
            'slug' => 'cc-engine-test-co',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);

        $this->cc = ConventionCollective::create([
            'idcc' => '3248',
            'short_name' => 'Métallurgie',
            'full_name' => 'Convention collective nationale de la métallurgie',
            'market_key' => 'FR',
            'brochure_number' => '3109',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'email' => 'jean@cc-test.com',
            'employee_number' => 'EMP-CC-001',
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
            'idempotency_key' => 'payroll-cc-engine:' . time() . ':' . uniqid(),
        ]);

        app()->instance('company.context', $this->company);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    // ── Helpers ──

    private function createLine(?int $contractId = null): PayrollLine
    {
        return $this->createLineForRun($this->run, $contractId);
    }

    private function createLineForRun(PayrollRun $run, ?int $contractId = null, ?int $employeeId = null, ?int $companyId = null): PayrollLine
    {
        $snapshot = [
            'base_salary_cents' => 350000,
            'overtime_rate_bps' => 2500,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'benefits' => [],
        ];
        if ($contractId) {
            $snapshot['contract_id'] = $contractId;
        }

        return PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $companyId ?? $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employeeId ?? $this->employee->id,
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
            'gross_breakdown' => ['base_salary_cents' => 350000, 'overtime_cents' => 0, 'gross_basis_cents' => 350000],
            'compensation_snapshot' => $snapshot,
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);
    }

    private function createAltRun(string $suffix): PayrollRun
    {
        return PayrollRun::withoutGlobalScopes()->create([
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
            'idempotency_key' => 'cc-alt:' . $suffix . ':' . uniqid(),
        ]);
    }

    private function createCCContributionRule(string $ruleKey, string $overrideMode, array $value): void
    {
        ConventionCollectiveRule::create([
            'convention_collective_id' => $this->cc->id,
            'domain' => 'workforce_payroll',
            'rule_type' => 'contribution',
            'rule_key' => $ruleKey,
            'value' => $value,
            'effective_from' => '2026-01-01',
            'override_mode' => $overrideMode,
            'source' => 'CC Métallurgie test',
        ]);
    }

    // ── 1. Calcul sans CC = identique Phase 3.1 ──

    public function test_calculation_without_cc_unchanged(): void
    {
        // Line without contract_id in snapshot
        $lineNoContract = $this->createLine();

        $engine = app(PayrollCalculationEngine::class);
        $result = $engine->calculate($lineNoContract);

        $this->assertEquals('payroll-calc-v2', $result->ruleVersion);

        // All rules are market_law
        foreach ($result->rulesApplied as $rule) {
            $this->assertEquals('market_law', $rule['source_level']);
        }

        // 20 contribution lines (market only — 9 original + 11 new in Sprint 5.1)
        $this->assertCount(20, $result->contributions->lines);

        // Contract with no CC → same result (separate run to avoid unique constraint)
        $run2 = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 350000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'cc-test-nocc:' . uniqid(),
        ]);
        $lineWithContract = $this->createLineForRun($run2, $this->contract->id);
        $resultB = $engine->calculate($lineWithContract);

        $this->assertEquals($result->net->netPayableCents, $resultB->net->netPayableCents);
    }

    // ── 2. Calcul avec CC replace ──

    public function test_calculation_with_cc_replace(): void
    {
        $this->contract->update(['convention_collective_id' => $this->cc->id]);

        // CC replaces maladie: employer 700 → 650
        $this->createCCContributionRule('contribution_urssaf_maladie', 'replace', [
            'code' => 'urssaf_maladie',
            'label' => 'Maladie (CC)',
            'category' => 'urssaf',
            'base_type' => 'deplafonne',
            'employee_rate_bps' => 0,
            'employer_rate_bps' => 650,
        ]);

        $line = $this->createLine($this->contract->id);
        $engine = app(PayrollCalculationEngine::class);
        $result = $engine->calculate($line);

        // Compare with market-only (separate run)
        $run2 = $this->createAltRun('cc-replace-ref');
        $lineNoCC = $this->createLineForRun($run2);
        $resultNoCC = $engine->calculate($lineNoCC);
        $this->assertNotEquals($result->contributions->totalEmployerCents, $resultNoCC->contributions->totalEmployerCents);

        // Verify the maladie rule was replaced
        $maladie = collect($result->rulesApplied)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals('convention_collective', $maladie['source_level']);
        $this->assertEquals(650, $maladie['employer_rate_bps']);
    }

    // ── 3. Calcul avec CC supplement ──

    public function test_calculation_with_cc_supplement(): void
    {
        $this->contract->update(['convention_collective_id' => $this->cc->id]);

        // CC adds mutuelle
        $this->createCCContributionRule('contribution_cc_mutuelle', 'supplement', [
            'code' => 'cc_mutuelle',
            'label' => 'Mutuelle CC',
            'category' => 'complementaire',
            'base_type' => 'deplafonne',
            'employee_rate_bps' => 50,
            'employer_rate_bps' => 100,
        ]);

        $line = $this->createLine($this->contract->id);
        $engine = app(PayrollCalculationEngine::class);
        $result = $engine->calculate($line);

        // 20 market + 1 CC supplement = 21 contribution lines
        $this->assertCount(21, $result->contributions->lines);

        // The supplemented mutuelle line exists
        $mutuelle = collect($result->contributions->lines)->first(fn ($l) => str_contains($l->code, 'mutuelle'));
        $this->assertNotNull($mutuelle);
        $this->assertEquals(50, $mutuelle->employeeRateBps);
        $this->assertEquals(100, $mutuelle->employerRateBps);

        // Employee contributions are higher (separate run for comparison)
        $run2 = $this->createAltRun('cc-suppl-ref');
        $lineNoCC = $this->createLineForRun($run2);
        $resultNoCC = $engine->calculate($lineNoCC);
        $this->assertGreaterThan($resultNoCC->contributions->totalEmployeeCents, $result->contributions->totalEmployeeCents);
    }

    // ── 4. Calcul avec company override ──

    public function test_calculation_with_company_override(): void
    {
        // Company override replaces maladie: 700 → 600
        CompanyPayrollRuleOverride::create([
            'company_id' => $this->company->id,
            'domain' => 'workforce_payroll',
            'rule_type' => 'contribution',
            'rule_key' => 'contribution_urssaf_maladie',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Maladie (accord)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 600,
            ],
            'effective_from' => '2026-01-01',
            'override_mode' => 'replace',
            'approved_by' => $this->user->id,
            'justification' => 'Accord CSE test',
            'source' => 'Accord test',
        ]);

        $line = $this->createLine($this->contract->id);
        $engine = app(PayrollCalculationEngine::class);
        $result = $engine->calculate($line);

        $maladie = collect($result->rulesApplied)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals('company_override', $maladie['source_level']);
        $this->assertEquals(600, $maladie['employer_rate_bps']);
    }

    // ── 5. Snapshot contient resolution_chain ──

    public function test_snapshot_contains_resolution_chain(): void
    {
        $this->contract->update(['convention_collective_id' => $this->cc->id]);

        $this->createCCContributionRule('contribution_urssaf_maladie', 'replace', [
            'code' => 'urssaf_maladie',
            'label' => 'Maladie (CC)',
            'category' => 'urssaf',
            'base_type' => 'deplafonne',
            'employee_rate_bps' => 0,
            'employer_rate_bps' => 650,
        ]);

        $line = $this->createLine($this->contract->id);
        $engine = app(PayrollCalculationEngine::class);
        $result = $engine->calculate($line);

        // ruleSnapshot contains CC context
        $this->assertNotNull($result->ruleSnapshot);
        $this->assertEquals('payroll-resolver-v2', $result->ruleSnapshot['resolver_version']);
        $this->assertNotNull($result->ruleSnapshot['convention_collective']);
        $this->assertEquals('3248', $result->ruleSnapshot['convention_collective']['idcc']);

        // rules_applied contain resolution_chain
        $maladie = collect($result->rulesApplied)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertContains('convention_collective', $maladie['resolution_chain']);

        // market-only rules have market_law chain
        $vieillesse = collect($result->rulesApplied)->first(fn ($r) => str_contains($r['rule_key'], 'vieillesse'));
        $this->assertEquals(['market_law'], $vieillesse['resolution_chain']);
    }

    // ── 6. Recompute reflète changement CC ──

    public function test_recompute_reflects_cc_change(): void
    {
        $this->contract->update(['convention_collective_id' => $this->cc->id]);
        $line = $this->createLine($this->contract->id);

        // First compute: no CC rules → market only
        $useCase = app(ComputePayrollCalculationsUseCase::class);
        $useCase->execute($this->run, $this->user->id);

        $calc1 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line->id)
            ->first();
        $net1 = $calc1->net_payable_cents;

        // Add CC supplement rule → changes calculations
        $this->createCCContributionRule('contribution_cc_mutuelle', 'supplement', [
            'code' => 'cc_mutuelle',
            'label' => 'Mutuelle CC',
            'category' => 'complementaire',
            'base_type' => 'deplafonne',
            'employee_rate_bps' => 50,
            'employer_rate_bps' => 100,
        ]);

        // Recompute: should pick up new CC rule
        $useCase->execute($this->run, $this->user->id);

        $calc2 = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line->id)
            ->first();
        $net2 = $calc2->net_payable_cents;

        // Net should decrease (more employee contributions from CC mutuelle)
        $this->assertLessThan($net1, $net2);

        // Snapshot should contain CC context
        $snapshot = $calc2->calculation_snapshot;
        $this->assertNotNull($snapshot['convention_collective']);
        $this->assertEquals('3248', $snapshot['convention_collective']['idcc']);
        $this->assertEquals('calc-snapshot-v3', $snapshot['snapshot_version']);
    }

    // ── 7. Export conserve données CC ──

    public function test_export_preserves_cc_calculated_data(): void
    {
        $this->contract->update(['convention_collective_id' => $this->cc->id]);

        $this->createCCContributionRule('contribution_cc_mutuelle', 'supplement', [
            'code' => 'cc_mutuelle',
            'label' => 'Mutuelle CC',
            'category' => 'complementaire',
            'base_type' => 'deplafonne',
            'employee_rate_bps' => 50,
            'employer_rate_bps' => 100,
        ]);

        $line = $this->createLine($this->contract->id);

        // Compute calculations
        app(ComputePayrollCalculationsUseCase::class)->execute($this->run, $this->user->id);

        // Validate run
        $this->run->update(['status' => PayrollRun::STATUS_VALIDATED]);

        // Export
        app(ExportPayrollUseCase::class)->execute($this->run, $this->user->id);
        $this->run->refresh();

        $this->assertEquals(PayrollRun::STATUS_EXPORTED, $this->run->status);

        // Verify the line's export_payload exists and has CC-affected data
        $line->refresh();
        $this->assertNotNull($line->export_payload);

        // Verify the calculation still has CC context in snapshot
        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $line->id)
            ->first();
        $this->assertNotNull($calc->calculation_snapshot['convention_collective']);
    }

    // ── 8. Multi-tenant isolation: CC/overrides scoped ──

    public function test_multi_tenant_cc_isolation(): void
    {
        // Company A: has CC + company override
        $this->contract->update(['convention_collective_id' => $this->cc->id]);

        $this->createCCContributionRule('contribution_cc_mutuelle', 'supplement', [
            'code' => 'cc_mutuelle',
            'label' => 'Mutuelle CC',
            'category' => 'complementaire',
            'base_type' => 'deplafonne',
            'employee_rate_bps' => 50,
            'employer_rate_bps' => 100,
        ]);

        CompanyPayrollRuleOverride::create([
            'company_id' => $this->company->id,
            'domain' => 'workforce_payroll',
            'rule_type' => 'contribution',
            'rule_key' => 'contribution_urssaf_maladie',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Maladie (accord)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 555,
            ],
            'effective_from' => '2026-01-01',
            'override_mode' => 'replace',
            'source' => 'Accord company A',
        ]);

        $engine = app(PayrollCalculationEngine::class);

        // Company A line (with CC + override)
        $lineA = $this->createLine($this->contract->id);
        $resultA = $engine->calculate($lineA);

        // Company A line without CC (separate run, no contract_id in snapshot)
        $runNoCC = $this->createAltRun('multi-tenant-nocc');
        $lineNoCC = $this->createLineForRun($runNoCC);
        $resultNoCC = $engine->calculate($lineNoCC);

        // Company A with CC has 21 lines (20 market + 1 CC supplement), without CC has 20
        $this->assertCount(21, $resultA->contributions->lines);
        $this->assertCount(20, $resultNoCC->contributions->lines);

        // Company A with CC: maladie has company_override (555 bps)
        $maladieA = collect($resultA->rulesApplied)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals('company_override', $maladieA['source_level']);
        $this->assertEquals(555, $maladieA['employer_rate_bps']);

        // Without CC: no company override applied (line has no company_id path via contract)
        // But company overrides use company_id from the line directly, so they still apply
        $maladieNoCC = collect($resultNoCC->rulesApplied)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals('company_override', $maladieNoCC['source_level']);
        $this->assertEquals(555, $maladieNoCC['employer_rate_bps']);

        // However, the CC supplement only appears when contract has CC
        $this->assertNotEquals(
            $resultA->contributions->totalEmployeeCents,
            $resultNoCC->contributions->totalEmployeeCents
        );
    }
}
