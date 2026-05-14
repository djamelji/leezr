<?php

namespace Tests\Unit;

use App\Core\Workforce\DTOs\BenefitResult;
use App\Core\Workforce\DTOs\ContributionResult;
use App\Core\Workforce\DTOs\DeductionResult;
use App\Core\Workforce\DTOs\TaxResult;
use App\Core\Workforce\Services\BenefitCalculator;
use App\Core\Workforce\Services\ContributionCalculator;
use App\Core\Workforce\Services\DeductionCalculator;
use App\Core\Workforce\Services\NetAggregator;
use App\Core\Workforce\Services\TaxCalculator;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    // ── T1: ContributionCalculator — base déplafonnée ──

    public function test_contribution_deplafonne_base_equals_gross(): void
    {
        $rules = [
            ['code' => 'maladie', 'label' => 'Maladie', 'category' => 'urssaf', 'base_type' => 'deplafonne', 'employee_rate_bps' => 0, 'employer_rate_bps' => 700],
        ];

        $result = ContributionCalculator::compute(350000, 383400, 9825, $rules);

        $this->assertCount(1, $result->lines);
        $this->assertEquals(350000, $result->lines[0]->baseCents);
        $this->assertEquals(0, $result->lines[0]->employeeCents);
        $this->assertEquals(24500, $result->lines[0]->employerCents); // 350000 * 700/10000
    }

    // ── T2: ContributionCalculator — base plafonnée SS ──

    public function test_contribution_plafonne_ss_caps_at_plafond(): void
    {
        $rules = [
            ['code' => 'vieillesse', 'label' => 'Vieillesse plaf', 'category' => 'urssaf', 'base_type' => 'plafonne_ss', 'employee_rate_bps' => 690, 'employer_rate_bps' => 855],
        ];

        // Gross above plafond
        $result = ContributionCalculator::compute(500000, 383400, 9825, $rules);

        $this->assertEquals(383400, $result->lines[0]->baseCents);
        $this->assertEquals((int) round(383400 * 690 / 10000), $result->lines[0]->employeeCents);
    }

    // ── T3: ContributionCalculator — tranche 2 ──

    public function test_contribution_tranche_2_above_plafond(): void
    {
        $rules = [
            ['code' => 'retraite_t2', 'label' => 'Retraite T2', 'category' => 'retraite', 'base_type' => 'tranche_2', 'employee_rate_bps' => 862, 'employer_rate_bps' => 1362],
        ];

        $result = ContributionCalculator::compute(500000, 383400, 9825, $rules);

        // tranche_2 base = max(0, min(gross, plafond*8) - plafond) = 500000 - 383400 = 116600
        $expectedBase = 500000 - 383400;
        $this->assertEquals($expectedBase, $result->lines[0]->baseCents);
    }

    // ── T4: ContributionCalculator — CSG base via multiplier ──

    public function test_contribution_csg_base_uses_multiplier_from_rules(): void
    {
        $rules = [
            ['code' => 'csg_deductible', 'label' => 'CSG déd', 'category' => 'csg_crds', 'base_type' => 'csg_base', 'employee_rate_bps' => 681, 'employer_rate_bps' => 0],
        ];

        $gross = 350000;
        $multiplier = 9825; // 98.25%

        $result = ContributionCalculator::compute($gross, 383400, $multiplier, $rules);

        $expectedBase = (int) round($gross * $multiplier / 10000); // 343875
        $this->assertEquals($expectedBase, $result->lines[0]->baseCents);
    }

    // ── T5: ContributionCalculator — gross sous plafond → tranche 2 = 0 ──

    public function test_contribution_tranche_2_zero_when_under_plafond(): void
    {
        $rules = [
            ['code' => 'retraite_t2', 'label' => 'Retraite T2', 'category' => 'retraite', 'base_type' => 'tranche_2', 'employee_rate_bps' => 862, 'employer_rate_bps' => 1362],
        ];

        $result = ContributionCalculator::compute(200000, 383400, 9825, $rules);

        $this->assertEquals(0, $result->lines[0]->baseCents);
        $this->assertEquals(0, $result->lines[0]->employeeCents);
        $this->assertEquals(0, $result->lines[0]->employerCents);
    }

    // ── T6: ContributionCalculator — gross = 0 → all zero ──

    public function test_contribution_zero_gross_all_zero(): void
    {
        $rules = [
            ['code' => 'maladie', 'label' => 'Maladie', 'category' => 'urssaf', 'base_type' => 'deplafonne', 'employee_rate_bps' => 0, 'employer_rate_bps' => 700],
            ['code' => 'vieillesse', 'label' => 'Vieillesse', 'category' => 'urssaf', 'base_type' => 'plafonne_ss', 'employee_rate_bps' => 690, 'employer_rate_bps' => 855],
        ];

        $result = ContributionCalculator::compute(0, 383400, 9825, $rules);

        $this->assertEquals(0, $result->totalEmployeeCents);
        $this->assertEquals(0, $result->totalEmployerCents);
    }

    // ── T7: TaxCalculator — normal rate, floor rounding ──

    public function test_tax_normal_rate_floor_rounding(): void
    {
        // gross=350000, contrib_employee=78750, csg_non_ded=830
        // taxable = 350000 - 78750 + 830 = 272080
        // tax = floor(272080 * 1100 / 10000) = floor(29928.8) = 29928
        $result = TaxCalculator::compute(350000, 78750, 830, 1100);

        $this->assertEquals(272080, $result->taxableIncomeCents);
        $this->assertEquals(29928, $result->taxCents);
        $this->assertEquals(1100, $result->taxRateBps);
    }

    // ── T8: TaxCalculator — taux 0 → tax = 0 ──

    public function test_tax_zero_rate(): void
    {
        $result = TaxCalculator::compute(350000, 78750, 830, 0);

        $this->assertEquals(0, $result->taxCents);
        $this->assertEquals(0, $result->taxRateBps);
    }

    // ── T9: TaxCalculator — taux clamped at 4300 ──

    public function test_tax_rate_clamped_at_4300(): void
    {
        $result = TaxCalculator::compute(350000, 78750, 830, 5000);

        // Should be clamped to 4300
        $this->assertEquals(4300, $result->taxRateBps);
        // tax = floor(272080 * 4300 / 10000) = floor(116994.4) = 116994
        $expected = (int) floor(272080 * 4300 / 10000);
        $this->assertEquals($expected, $result->taxCents);
    }

    // ── T10: NetAggregator — fundamental equation ──

    public function test_net_aggregator_fundamental_equation(): void
    {
        $contributions = new ContributionResult([], 78750, 148400, 830);
        $tax = new TaxResult(272080, 1100, 29928);
        $benefits = new BenefitResult(0, 0, 0, []);
        $deductions = new DeductionResult(0, []);

        $net = NetAggregator::aggregate(350000, $contributions, $tax, $benefits, $deductions);

        // net_before_tax = 350000 - 78750 - 0 = 271250
        $this->assertEquals(271250, $net->netBeforeTaxCents);
        // net_payable = 271250 - 29928 = 241322
        $this->assertEquals(241322, $net->netPayableCents);
        // total_cost_employer = 350000 + 148400 = 498400
        $this->assertEquals(498400, $net->totalCostEmployerCents);

        // Invariant: net_payable = gross - contributions_employee - tax - deductions
        $this->assertEquals(
            350000 - 78750 - 29928 - 0,
            $net->netPayableCents
        );
    }

    // ── T11: NetAggregator — negative net detected ──

    public function test_net_aggregator_negative_net(): void
    {
        // Huge contributions that exceed gross
        $contributions = new ContributionResult([], 400000, 148400, 0);
        $tax = new TaxResult(0, 0, 0);
        $benefits = new BenefitResult(0, 0, 0, []);
        $deductions = new DeductionResult(0, []);

        $net = NetAggregator::aggregate(350000, $contributions, $tax, $benefits, $deductions);

        $this->assertLessThan(0, $net->netPayableCents);
    }

    // ── T12: BenefitCalculator — taxable benefit ──

    public function test_benefit_taxable_added_to_gross(): void
    {
        $benefits = [
            ['code' => 'car', 'label' => 'Voiture', 'amount_cents' => 15000, 'taxable' => true, 'is_cash' => false],
        ];

        $result = BenefitCalculator::compute($benefits);

        $this->assertEquals(15000, $result->taxableBenefitsCents);
        $this->assertEquals(15000, $result->nonCashBenefitsCents);
        $this->assertEquals(0, $result->cashBenefitsCents);
    }

    // ── T13: BenefitCalculator — non-taxable benefit NOT in gross ──

    public function test_benefit_non_taxable_not_in_gross(): void
    {
        $benefits = [
            ['code' => 'meal', 'label' => 'Tickets restaurant', 'amount_cents' => 5000, 'taxable' => false, 'is_cash' => false],
        ];

        $result = BenefitCalculator::compute($benefits);

        $this->assertEquals(0, $result->taxableBenefitsCents);
    }

    // ── T14: No double-counting of benefits ──

    public function test_no_double_counting_benefits(): void
    {
        $taxableBenefits = 15000;
        $grossBasis = 350000;
        $grossTotal = $grossBasis + $taxableBenefits; // 365000

        $contributions = new ContributionResult([], 80000, 150000, 900);
        $tax = new TaxResult(285900, 1100, 31449);
        $benefits = new BenefitResult($taxableBenefits, $taxableBenefits, 0, []);
        $deductions = new DeductionResult(0, []);

        $net = NetAggregator::aggregate($grossTotal, $contributions, $tax, $benefits, $deductions);

        // Benefits are ALREADY in grossTotal — NetAggregator must NOT add them again
        // net_before_tax = grossTotal - contributions_employee - deductions = 365000 - 80000 = 285000
        $this->assertEquals(365000 - 80000 - 0, $net->netBeforeTaxCents);
        // net_payable = net_before_tax - tax = 285000 - 31449 = 253551
        $this->assertEquals(285000 - 31449, $net->netPayableCents);
    }

    // ── T15: CSG multiplier comes from parameter, not hardcoded ──

    public function test_csg_multiplier_from_parameter(): void
    {
        $rules = [
            ['code' => 'csg_deductible', 'label' => 'CSG', 'category' => 'csg_crds', 'base_type' => 'csg_base', 'employee_rate_bps' => 681, 'employer_rate_bps' => 0],
        ];

        $gross = 350000;

        // Standard multiplier 98.25%
        $result1 = ContributionCalculator::compute($gross, 383400, 9825, $rules);
        $base1 = $result1->lines[0]->baseCents;

        // Different multiplier 97.00%
        $result2 = ContributionCalculator::compute($gross, 383400, 9700, $rules);
        $base2 = $result2->lines[0]->baseCents;

        // Different multiplier → different base → different result
        $this->assertNotEquals($base1, $base2);
        $this->assertEquals((int) round($gross * 9825 / 10000), $base1);
        $this->assertEquals((int) round($gross * 9700 / 10000), $base2);
    }

    // ── T16: DeductionCalculator — acompte deducted from net ──

    public function test_deduction_acompte(): void
    {
        $deductions = [
            ['code' => 'acompte', 'label' => 'Acompte', 'amount_cents' => 50000],
        ];

        $result = DeductionCalculator::compute($deductions);

        $this->assertEquals(50000, $result->totalCents);
        $this->assertCount(1, $result->lines);

        // Verify it reduces net via aggregator
        $contributions = new ContributionResult([], 78750, 148400, 830);
        $tax = new TaxResult(272080, 1100, 29928);
        $benefits = new BenefitResult(0, 0, 0, []);
        $netWithout = NetAggregator::aggregate(350000, $contributions, $tax, $benefits, new DeductionResult(0, []));
        $netWith = NetAggregator::aggregate(350000, $contributions, $tax, $benefits, $result);

        $this->assertEquals($netWithout->netPayableCents - 50000, $netWith->netPayableCents);
    }
}
