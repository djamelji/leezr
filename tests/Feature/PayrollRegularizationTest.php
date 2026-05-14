<?php

namespace Tests\Feature;

use App\Core\Workforce\DTOs\ContributionResult;
use App\Core\Workforce\Services\ContributionCalculator;
use App\Core\Workforce\Services\RegularizationCalculator;
use Tests\TestCase;

/**
 * Tests for RegularizationCalculator — progressive regularization URSSAF.
 * Pure unit tests — no DB, no models, no seeder.
 */
class PayrollRegularizationTest extends TestCase
{
    // Plafond SS mensuel: 386400 cents (3864 €)
    private const PLAFOND = 386400;

    // CSG base multiplier: 9825 bps = 98.25%
    private const CSG_MULTIPLIER = 9825;

    /**
     * Simplified contribution rules for testable arithmetic.
     * Each rate is easy to verify manually.
     */
    private function simpleRules(): array
    {
        return [
            [
                'code' => 'deplaf_test',
                'label' => 'Déplafonné test',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 100, // 1%
                'employer_rate_bps' => 200, // 2%
            ],
            [
                'code' => 'plaf_test',
                'label' => 'Plafonné test',
                'category' => 'urssaf',
                'base_type' => 'plafonne_ss',
                'employee_rate_bps' => 100, // 1%
                'employer_rate_bps' => 200, // 2%
            ],
            [
                'code' => 'tranche2_test',
                'label' => 'Tranche 2 test',
                'category' => 'retraite',
                'base_type' => 'tranche_2',
                'employee_rate_bps' => 100, // 1%
                'employer_rate_bps' => 200, // 2%
            ],
        ];
    }

    /**
     * Build prior cumuls array matching YtdCalculator output format.
     */
    private function buildPriorCumuls(
        int $ytdGross,
        array $monthsIncluded,
        array $contributionLines = [],
    ): array {
        return [
            'ytd_gross_total_cents' => $ytdGross,
            'months_included' => $monthsIncluded,
            'ytd_contribution_lines' => $contributionLines,
        ];
    }

    // ── Test 1: Month 1 without prior → identical to monthly ──

    public function test_month1_without_prior_equals_monthly(): void
    {
        $gross = 350000; // 3500 €
        $rules = $this->simpleRules();

        $monthly = ContributionCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules);
        $regularized = RegularizationCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules, null);

        // Must be identical
        $this->assertEquals($monthly->totalEmployeeCents, $regularized->totalEmployeeCents);
        $this->assertEquals($monthly->totalEmployerCents, $regularized->totalEmployerCents);
        $this->assertCount(count($monthly->lines), $regularized->lines);

        foreach ($monthly->lines as $i => $monthlyLine) {
            $regLine = $regularized->lines[$i];
            $this->assertEquals($monthlyLine->code, $regLine->code);
            $this->assertEquals($monthlyLine->baseCents, $regLine->baseCents);
            $this->assertEquals($monthlyLine->employeeCents, $regLine->employeeCents);
            $this->assertEquals($monthlyLine->employerCents, $regLine->employerCents);
        }
    }

    // ── Test 2: Month 2 with prior, same salary → delta equals monthly ──

    public function test_month2_same_salary_delta_equals_monthly(): void
    {
        $gross = 350000; // 3500 € (under plafond 3864 €)
        $rules = $this->simpleRules();

        // Month 1 result (used as prior)
        $month1 = ContributionCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules);

        // Build prior cumuls from month 1
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $gross,
            monthsIncluded: [1],
        );

        // Month 2 regularized
        $regularized = RegularizationCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);

        // Same salary, both under plafond → delta = monthly amounts
        // Déplafonné: base = 3500. Same each month.
        $this->assertEquals($month1->totalEmployeeCents, $regularized->totalEmployeeCents);
        $this->assertEquals($month1->totalEmployerCents, $regularized->totalEmployerCents);
    }

    // ── Test 3: Variable salary → cumulative plafonnement correct ──

    public function test_variable_salary_cumulative_plafonnement(): void
    {
        $rules = $this->simpleRules();
        $grossMonth1 = 500000; // 5000 € (above plafond 3864 €)
        $grossMonth2 = 200000; // 2000 € (below plafond)

        // Month 1 monthly (reference)
        $month1Monthly = ContributionCalculator::compute($grossMonth1, self::PLAFOND, self::CSG_MULTIPLIER, $rules);

        // Month 2 monthly (reference — without regularization)
        $month2Monthly = ContributionCalculator::compute($grossMonth2, self::PLAFOND, self::CSG_MULTIPLIER, $rules);

        // Regularized month 2
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $grossMonth1,
            monthsIncluded: [1],
        );

        $regularized = RegularizationCalculator::compute($grossMonth2, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);

        // Verify plafonnée line: progressive should differ from monthly
        $regPlaf = collect($regularized->lines)->firstWhere('code', 'plaf_test');
        $monthlyPlaf = collect($month2Monthly->lines)->firstWhere('code', 'plaf_test');

        // Monthly M2: base = min(200000, 386400) = 200000
        $this->assertEquals(200000, $monthlyPlaf->baseCents);

        // Progressive M2: cumul_base = min(700000, 772800) = 700000
        //                 prior_base = min(500000, 386400) = 386400
        //                 delta_base = 700000 - 386400 = 313600
        $this->assertEquals(313600, $regPlaf->baseCents);

        // Progressive gives MORE plafonnée base (313600 > 200000)
        // because M1 was capped, M2 recovers the unused plafond
        $this->assertGreaterThan($monthlyPlaf->baseCents, $regPlaf->baseCents);

        // Employee contribution: 313600 × 100/10000 = 3136
        $this->assertEquals(3136, $regPlaf->employeeCents);

        // Déplafonné should be identical (no capping effect)
        $regDeplaf = collect($regularized->lines)->firstWhere('code', 'deplaf_test');
        $monthlyDeplaf = collect($month2Monthly->lines)->firstWhere('code', 'deplaf_test');
        $this->assertEquals($monthlyDeplaf->employeeCents, $regDeplaf->employeeCents);
    }

    // ── Test 4: Tranche 2 progressive — appears when cumul exceeds plafond ──

    public function test_tranche2_progressive_appears_when_cumul_exceeds_plafond(): void
    {
        $rules = $this->simpleRules();
        $grossMonth1 = 300000; // 3000 € (under plafond → T2 = 0)
        $grossMonth2 = 600000; // 6000 € (above plafond → T2 > 0)

        // Month 1: T2 = max(0, min(300000, 386400*8) - 386400) = max(0, 300000 - 386400) = 0
        $month1 = ContributionCalculator::compute($grossMonth1, self::PLAFOND, self::CSG_MULTIPLIER, $rules);
        $month1T2 = collect($month1->lines)->firstWhere('code', 'tranche2_test');
        $this->assertEquals(0, $month1T2->baseCents);

        // Month 2 monthly: T2 = max(0, min(600000, 386400*8) - 386400) = max(0, 600000 - 386400) = 213600
        $month2Monthly = ContributionCalculator::compute($grossMonth2, self::PLAFOND, self::CSG_MULTIPLIER, $rules);
        $month2T2Monthly = collect($month2Monthly->lines)->firstWhere('code', 'tranche2_test');
        $this->assertEquals(213600, $month2T2Monthly->baseCents);

        // Progressive M2: cumul gross = 900000, plafond cumul = 772800
        // T2 cumul = max(0, min(900000, 772800*8) - 772800) = max(0, 900000 - 772800) = 127200
        // Prior T2 = 0 (month 1 was under plafond)
        // Delta T2 = 127200 - 0 = 127200
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $grossMonth1,
            monthsIncluded: [1],
        );

        $regularized = RegularizationCalculator::compute($grossMonth2, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);
        $regT2 = collect($regularized->lines)->firstWhere('code', 'tranche2_test');

        $this->assertEquals(127200, $regT2->baseCents);

        // Progressive T2 < monthly T2 (because M1 didn't use any plafond room)
        $this->assertLessThan($month2T2Monthly->baseCents, $regT2->baseCents);

        // Contribution: 127200 × 100/10000 = 1272
        $this->assertEquals(1272, $regT2->employeeCents);
    }

    // ── Test 5: Negative regularization — T2 reimbursement ──

    public function test_negative_regularization_tranche2(): void
    {
        $rules = $this->simpleRules();
        $grossMonth1 = 500000; // 5000 € → T2 = 500000 - 386400 = 113600
        $grossMonth2 = 200000; // 2000 €

        // Progressive M2:
        // Cumul = 700000, plafond = 772800
        // T2 cumul = max(0, 700000 - 772800) = 0
        // Prior T2 = max(0, 500000 - 386400) = 113600
        // Delta T2 = 0 - 113600 = -113600 → NEGATIVE (reimbursement)
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $grossMonth1,
            monthsIncluded: [1],
        );

        $regularized = RegularizationCalculator::compute($grossMonth2, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);
        $regT2 = collect($regularized->lines)->firstWhere('code', 'tranche2_test');

        // Negative base and amounts
        $this->assertEquals(-113600, $regT2->baseCents);
        $this->assertEquals(-1136, $regT2->employeeCents); // -113600 × 100/10000
        $this->assertEquals(-2272, $regT2->employerCents); // -113600 × 200/10000
    }

    // ── Test 6: Contribution lines preserved by code ──

    public function test_contribution_lines_preserved_by_code(): void
    {
        $rules = $this->simpleRules();
        $gross = 350000;

        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $gross,
            monthsIncluded: [1],
        );

        $regularized = RegularizationCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);

        // All 3 codes must be present
        $codes = array_map(fn ($l) => $l->code, $regularized->lines);
        $this->assertContains('deplaf_test', $codes);
        $this->assertContains('plaf_test', $codes);
        $this->assertContains('tranche2_test', $codes);
        $this->assertCount(3, $regularized->lines);

        // Each line preserves metadata
        foreach ($regularized->lines as $line) {
            $this->assertNotEmpty($line->label);
            $this->assertNotEmpty($line->category);
            $this->assertNotEmpty($line->baseType);
            $this->assertGreaterThanOrEqual(0, $line->employeeRateBps);
            $this->assertGreaterThanOrEqual(0, $line->employerRateBps);
        }
    }

    // ── Test 7: Idempotence ──

    public function test_compute_is_idempotent(): void
    {
        $gross = 400000;
        $rules = $this->simpleRules();

        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: 350000,
            monthsIncluded: [1],
        );

        $result1 = RegularizationCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);
        $result2 = RegularizationCalculator::compute($gross, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);

        $this->assertEquals($result1->totalEmployeeCents, $result2->totalEmployeeCents);
        $this->assertEquals($result1->totalEmployerCents, $result2->totalEmployerCents);
        $this->assertEquals($result1->csgNonDeductibleCents, $result2->csgNonDeductibleCents);

        foreach ($result1->lines as $i => $line1) {
            $line2 = $result2->lines[$i];
            $this->assertEquals($line1->baseCents, $line2->baseCents);
            $this->assertEquals($line1->employeeCents, $line2->employeeCents);
            $this->assertEquals($line1->employerCents, $line2->employerCents);
        }
    }

    // ── Test 8: Multi-month (3 months) cumulative accuracy ──

    public function test_three_month_cumulative_accuracy(): void
    {
        $rules = $this->simpleRules();
        $grossM1 = 350000;
        $grossM2 = 400000;
        $grossM3 = 300000;

        // All under plafond individually, and cumulative (1050000 < 1159200)
        // So for plafonnée, regularized = monthly (no capping effect)

        // Month 3 with prior = months 1+2
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $grossM1 + $grossM2, // 750000
            monthsIncluded: [1, 2],
        );

        $regularized = RegularizationCalculator::compute($grossM3, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);

        // Déplafonné: delta base = 300000 (just month 3 gross)
        $deplaf = collect($regularized->lines)->firstWhere('code', 'deplaf_test');
        $this->assertEquals($grossM3, $deplaf->baseCents);

        // Verify cumul consistency: cumul result - prior result = delta
        $cumulResult = ContributionCalculator::compute(
            $grossM1 + $grossM2 + $grossM3,
            self::PLAFOND * 3,
            self::CSG_MULTIPLIER,
            $rules,
        );
        $priorResult = ContributionCalculator::compute(
            $grossM1 + $grossM2,
            self::PLAFOND * 2,
            self::CSG_MULTIPLIER,
            $rules,
        );

        $this->assertEquals(
            $cumulResult->totalEmployeeCents - $priorResult->totalEmployeeCents,
            $regularized->totalEmployeeCents,
        );
        $this->assertEquals(
            $cumulResult->totalEmployerCents - $priorResult->totalEmployerCents,
            $regularized->totalEmployerCents,
        );
    }

    // ── Test 9: CSG non-deductible correctly regularized ──

    public function test_csg_non_deductible_regularized(): void
    {
        $rules = [
            [
                'code' => 'csg_non_deductible',
                'label' => 'CSG non déductible',
                'category' => 'csg',
                'base_type' => 'csg_base',
                'employee_rate_bps' => 242,
                'employer_rate_bps' => 0,
            ],
        ];

        $grossM1 = 350000;
        $grossM2 = 400000;

        // Month 1 (no prior)
        $result1 = RegularizationCalculator::compute($grossM1, self::PLAFOND, self::CSG_MULTIPLIER, $rules, null);
        $this->assertGreaterThan(0, $result1->csgNonDeductibleCents);

        // Month 2 (with prior)
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: $grossM1,
            monthsIncluded: [1],
        );

        $result2 = RegularizationCalculator::compute($grossM2, self::PLAFOND, self::CSG_MULTIPLIER, $rules, $priorCumuls);
        $this->assertGreaterThan(0, $result2->csgNonDeductibleCents);

        // CSG base = gross × 98.25%. Regularized delta should track correctly
        $csgLine = $result2->lines[0];
        $this->assertEquals('csg_non_deductible', $csgLine->code);
        $this->assertEquals($result2->csgNonDeductibleCents, $csgLine->employeeCents);
    }
}
