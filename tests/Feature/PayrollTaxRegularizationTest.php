<?php

namespace Tests\Feature;

use App\Core\Workforce\DTOs\TaxResult;
use App\Core\Workforce\Services\TaxCalculator;
use App\Core\Workforce\Services\TaxRegularizationCalculator;
use Tests\TestCase;

/**
 * Tests for TaxRegularizationCalculator — progressive PAS regularization.
 * Pure unit tests — no DB, no models, no seeder.
 *
 * Reference values (round numbers for manual verification):
 *   gross = 300000 (3000 €), contrib_emp = 60000, csg_nd = 5000
 *   taxable = 300000 - 60000 + 5000 = 245000
 *   @700bps: tax = floor(245000 × 700 / 10000) = 17150
 */
class PayrollTaxRegularizationTest extends TestCase
{
    private const GROSS = 300000;
    private const CONTRIB_EMP = 60000;
    private const CSG_ND = 5000;
    private const TAXABLE = 245000; // 300000 - 60000 + 5000
    private const RATE = 700; // 7%

    /**
     * Build prior cumuls matching YtdCalculator output format.
     */
    private function buildPriorCumuls(
        int $ytdGross,
        int $ytdContribEmp,
        int $ytdTax,
        array $monthsIncluded,
        int $csgNdCumul = 0,
        int $ytdTaxable = 0,
    ): array {
        $contribLines = [];
        if ($csgNdCumul > 0) {
            $contribLines[] = [
                'code' => 'csg_non_deductible',
                'label' => 'CSG non déductible',
                'category' => 'csg',
                'ytd_employee_cents' => $csgNdCumul,
                'ytd_employer_cents' => 0,
            ];
        }

        return [
            'ytd_gross_total_cents' => $ytdGross,
            'ytd_contributions_employee_cents' => $ytdContribEmp,
            'ytd_taxable_income_cents' => $ytdTaxable,
            'ytd_tax_cents' => $ytdTax,
            'months_included' => $monthsIncluded,
            'ytd_contribution_lines' => $contribLines,
        ];
    }

    // ── Test 1: Month 1 without prior → identical to TaxCalculator ──

    public function test_month1_without_prior_equals_tax_calculator(): void
    {
        $monthly = TaxCalculator::compute(self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE);
        $regularized = TaxRegularizationCalculator::compute(self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE, null);

        $this->assertEquals($monthly->taxableIncomeCents, $regularized->taxableIncomeCents);
        $this->assertEquals($monthly->taxRateBps, $regularized->taxRateBps);
        $this->assertEquals($monthly->taxCents, $regularized->taxCents);

        // Verify known values
        $this->assertEquals(self::TAXABLE, $regularized->taxableIncomeCents);
        $this->assertEquals(17150, $regularized->taxCents); // floor(245000 × 700 / 10000)
    }

    // ── Test 2: Month 2, same salary → delta correct ──

    public function test_month2_same_salary_delta_correct(): void
    {
        // Month 1: tax = 17150
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: self::GROSS,
            ytdContribEmp: self::CONTRIB_EMP,
            ytdTax: 17150,
            monthsIncluded: [1],
            csgNdCumul: self::CSG_ND,
        );

        $regularized = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE, $priorCumuls
        );

        // ytd_gross = 600000, ytd_contrib = 120000, ytd_csg_nd = 10000
        // ytd_taxable = 600000 - 120000 + 10000 = 490000
        // ytd_tax = floor(490000 × 700 / 10000) = 34300
        // tax_month = 34300 - 17150 = 17150 (same as M1 — round numbers)
        $this->assertEquals(17150, $regularized->taxCents);
        $this->assertEquals(self::RATE, $regularized->taxRateBps);
        $this->assertEquals(self::TAXABLE, $regularized->taxableIncomeCents);
    }

    // ── Test 3: Rate change mid-year → catch-up regularization ──

    public function test_rate_change_catches_up(): void
    {
        // Month 1 @700bps: tax = 17150
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: self::GROSS,
            ytdContribEmp: self::CONTRIB_EMP,
            ytdTax: 17150,
            monthsIncluded: [1],
            csgNdCumul: self::CSG_ND,
        );

        // Month 2 @1100bps (rate increased)
        $newRate = 1100;
        $regularized = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, $newRate, $priorCumuls
        );

        // ytd_taxable = 490000
        // ytd_tax @1100bps = floor(490000 × 1100 / 10000) = 53900
        // tax_month = 53900 - 17150 = 36750
        $this->assertEquals(36750, $regularized->taxCents);

        // Monthly M2 @1100bps without regularization: floor(245000 × 1100 / 10000) = 26950
        $monthlyM2 = TaxCalculator::compute(self::GROSS, self::CONTRIB_EMP, self::CSG_ND, $newRate);
        $this->assertEquals(26950, $monthlyM2->taxCents);

        // Regularized > monthly → catch-up for undercollection in M1
        $this->assertGreaterThan($monthlyM2->taxCents, $regularized->taxCents);
        $this->assertEquals($newRate, $regularized->taxRateBps);
    }

    // ── Test 4: Rate decrease → negative PAS (refund) ──

    public function test_rate_decrease_produces_negative_refund(): void
    {
        // Month 1 @1100bps: tax = floor(245000 × 1100 / 10000) = 26950
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: self::GROSS,
            ytdContribEmp: self::CONTRIB_EMP,
            ytdTax: 26950,
            monthsIncluded: [1],
            csgNdCumul: self::CSG_ND,
        );

        // Month 2 @200bps (rate decreased drastically)
        $lowRate = 200;
        $regularized = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, $lowRate, $priorCumuls
        );

        // ytd_taxable = 490000
        // ytd_tax @200bps = floor(490000 × 200 / 10000) = 9800
        // tax_month = 9800 - 26950 = -17150 → NEGATIVE (refund)
        $this->assertEquals(-17150, $regularized->taxCents);
        $this->assertLessThan(0, $regularized->taxCents);
    }

    // ── Test 5: Idempotence ──

    public function test_compute_is_idempotent(): void
    {
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: self::GROSS,
            ytdContribEmp: self::CONTRIB_EMP,
            ytdTax: 17150,
            monthsIncluded: [1],
            csgNdCumul: self::CSG_ND,
        );

        $result1 = TaxRegularizationCalculator::compute(self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE, $priorCumuls);
        $result2 = TaxRegularizationCalculator::compute(self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE, $priorCumuls);

        $this->assertEquals($result1->taxableIncomeCents, $result2->taxableIncomeCents);
        $this->assertEquals($result1->taxRateBps, $result2->taxRateBps);
        $this->assertEquals($result1->taxCents, $result2->taxCents);
    }

    // ── Test 6: Three months cumulative consistency ──

    public function test_three_months_cumulative_consistency(): void
    {
        // M1 @700: tax = 17150
        // M2 @700: ytd_tax = 34300, delta = 17150
        // M3 @700: ytd_tax = 51450, delta = 17150

        $priorM2 = $this->buildPriorCumuls(
            ytdGross: self::GROSS * 2,
            ytdContribEmp: self::CONTRIB_EMP * 2,
            ytdTax: 34300,
            monthsIncluded: [1, 2],
            csgNdCumul: self::CSG_ND * 2,
        );

        $regularizedM3 = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE, $priorM2
        );

        // ytd_gross = 900000, ytd_contrib = 180000, ytd_csg_nd = 15000
        // ytd_taxable = 900000 - 180000 + 15000 = 735000
        // ytd_tax = floor(735000 × 700 / 10000) = 51450
        // tax_M3 = 51450 - 34300 = 17150
        $this->assertEquals(17150, $regularizedM3->taxCents);
    }

    // ── Test 7: Variable salary with regularized contributions ──

    public function test_variable_salary_with_regularized_contributions(): void
    {
        // M1: gross=400000, contrib=80000, csg_nd=6000, taxable=326000
        //     tax @700 = floor(326000 × 700 / 10000) = 22820
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: 400000,
            ytdContribEmp: 80000,
            ytdTax: 22820,
            monthsIncluded: [1],
            csgNdCumul: 6000,
        );

        // M2: gross=200000, contrib=50000 (regularized), csg_nd=3000
        $regularized = TaxRegularizationCalculator::compute(
            200000, 50000, 3000, self::RATE, $priorCumuls
        );

        // ytd_gross = 600000, ytd_contrib = 130000, ytd_csg_nd = 9000
        // ytd_taxable = 600000 - 130000 + 9000 = 479000
        // ytd_tax = floor(479000 × 700 / 10000) = 33530
        // tax_M2 = 33530 - 22820 = 10710
        $this->assertEquals(10710, $regularized->taxCents);

        // Monthly M2 taxable = 200000 - 50000 + 3000 = 153000
        $this->assertEquals(153000, $regularized->taxableIncomeCents);
    }

    // ── Test 8: Rate clamped to legal bounds ──

    public function test_rate_clamped_to_legal_bounds(): void
    {
        // Rate above 43% → clamped
        $result = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, 5000, null
        );
        $this->assertEquals(4300, $result->taxRateBps);

        // Rate below 0% → clamped
        $result = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, -100, null
        );
        $this->assertEquals(0, $result->taxRateBps);
        $this->assertEquals(0, $result->taxCents);
    }

    // ── Test 9: No CSG non-deductible in prior → graceful handling ──

    public function test_no_csg_non_deductible_in_prior(): void
    {
        // Prior cumuls without CSG non-deductible line
        $priorCumuls = $this->buildPriorCumuls(
            ytdGross: self::GROSS,
            ytdContribEmp: self::CONTRIB_EMP,
            ytdTax: 16800, // tax without csg_nd: floor((300000-60000) × 700/10000) = 16800
            monthsIncluded: [1],
            csgNdCumul: 0, // no CSG ND
        );

        $regularized = TaxRegularizationCalculator::compute(
            self::GROSS, self::CONTRIB_EMP, self::CSG_ND, self::RATE, $priorCumuls
        );

        // ytd_gross = 600000, ytd_contrib = 120000, ytd_csg_nd = 0 + 5000 = 5000
        // ytd_taxable = 600000 - 120000 + 5000 = 485000
        // ytd_tax = floor(485000 × 700 / 10000) = 33950
        // tax_M2 = 33950 - 16800 = 17150
        $this->assertEquals(17150, $regularized->taxCents);
        $this->assertNotNull($regularized);
    }
}
