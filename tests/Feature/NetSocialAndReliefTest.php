<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Workforce\DTOs\ContributionLine;
use App\Core\Workforce\DTOs\ContributionResult;
use App\Core\Workforce\DTOs\ReliefResult;
use App\Core\Workforce\PayrollCalculationEngine;
use App\Core\Workforce\Services\ContributionCalculator;
use App\Core\Workforce\Services\NetSocialCalculator;
use App\Core\Workforce\Services\PayrollRuleResolver;
use Carbon\Carbon;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 5.2 — Net social calculation + relief structure.
 * ADR-513: display-only bulletin data, never a payroll source of truth.
 */
class NetSocialAndReliefTest extends TestCase
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
    // T1: NetSocialCalculator — simple case
    // ──────────────────────────────────────
    public function test_T1_net_social_simple_case(): void
    {
        // Build a ContributionResult with known values
        $lines = [
            new ContributionLine('maladie', 'Maladie', 'urssaf', 'deplafonne', 350000, 0, 700, 0, 24500),
            new ContributionLine('vieillesse_plaf', 'Vieillesse plaf', 'urssaf', 'plafonne_ss', 350000, 690, 855, 24150, 29925),
            new ContributionLine('csg_deductible', 'CSG déductible', 'urssaf', 'csg', 343875, 681, 0, 23418, 0),
            new ContributionLine('csg_non_deductible', 'CSG non déductible', 'urssaf', 'csg', 343875, 242, 0, 8322, 0),
            new ContributionLine('crds', 'CRDS', 'urssaf', 'csg', 343875, 50, 0, 1719, 0),
        ];

        $totalEmployee = 24150 + 23418 + 8322 + 1719; // 57609
        $totalEmployer = 24500 + 29925; // 54425

        $contributions = new ContributionResult(
            lines: $lines,
            totalEmployeeCents: $totalEmployee,
            totalEmployerCents: $totalEmployer,
            csgNonDeductibleCents: 8322,
        );

        $netSocial = NetSocialCalculator::compute(350000, $contributions);

        // Net social = Brut - cotisations salariales + CSG non-déductible + CRDS
        // = 350000 - 57609 + 8322 + 1719 = 302432
        $expected = 350000 - $totalEmployee + 8322 + 1719;
        $this->assertEquals($expected, $netSocial);
        $this->assertGreaterThan(0, $netSocial);
        $this->assertLessThan(350000, $netSocial);
    }

    // ──────────────────────────────────────
    // T2: Net social with zero contributions
    // ──────────────────────────────────────
    public function test_T2_net_social_zero_contributions_equals_gross(): void
    {
        $contributions = new ContributionResult(
            lines: [],
            totalEmployeeCents: 0,
            totalEmployerCents: 0,
            csgNonDeductibleCents: 0,
        );

        $netSocial = NetSocialCalculator::compute(350000, $contributions);
        $this->assertEquals(350000, $netSocial, 'Net social with no contributions should equal gross');
    }

    // ──────────────────────────────────────
    // T3: Net social without CRDS line gracefully returns 0 for CRDS
    // ──────────────────────────────────────
    public function test_T3_net_social_no_crds_line_graceful(): void
    {
        $lines = [
            new ContributionLine('maladie', 'Maladie', 'urssaf', 'deplafonne', 350000, 0, 700, 0, 24500),
            new ContributionLine('csg_non_deductible', 'CSG ND', 'urssaf', 'csg', 343875, 242, 0, 8322, 0),
        ];

        $contributions = new ContributionResult(
            lines: $lines,
            totalEmployeeCents: 8322,
            totalEmployerCents: 24500,
            csgNonDeductibleCents: 8322,
        );

        $netSocial = NetSocialCalculator::compute(350000, $contributions);

        // Without CRDS: Net social = 350000 - 8322 + 8322 + 0 = 350000
        $this->assertEquals(350000, $netSocial);
    }

    // ──────────────────────────────────────
    // T4: ReliefResult stub structure
    // ──────────────────────────────────────
    public function test_T4_relief_stub_has_zero_values(): void
    {
        $relief = ReliefResult::stub();

        $this->assertEquals(0, $relief->totalEmployerReliefCents);
        $this->assertEmpty($relief->lines);
        $this->assertEquals([
            'total_employer_relief_cents' => 0,
            'lines' => [],
        ], $relief->toArray());
    }

    // ──────────────────────────────────────
    // T5: ReliefResult with real data
    // ──────────────────────────────────────
    public function test_T5_relief_result_with_real_data(): void
    {
        $relief = new ReliefResult(
            totalEmployerReliefCents: 15000,
            lines: [
                ['code' => 'rgdu', 'label' => 'Réduction générale', 'employer_relief_cents' => 15000],
            ],
        );

        $this->assertEquals(15000, $relief->totalEmployerReliefCents);
        $this->assertCount(1, $relief->lines);
        $array = $relief->toArray();
        $this->assertEquals(15000, $array['total_employer_relief_cents']);
        $this->assertEquals('rgdu', $array['lines'][0]['code']);
    }

    // ──────────────────────────────────────
    // T6: CalculationResult backward compat (no new params)
    // ──────────────────────────────────────
    public function test_T6_calculation_result_backward_compat(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $contributions = ContributionCalculator::compute(350000, $plafond, $csgMultiplier, $rules);

        // Old-style construction without netSocialCents/reliefs — must not throw
        $result = new \App\Core\Workforce\DTOs\CalculationResult(
            ruleVersion: 'test-v1',
            plafondSSMonthlyCents: $plafond,
            grossTotalCents: 350000,
            contributions: $contributions,
            tax: new \App\Core\Workforce\DTOs\TaxResult(0, 0, 0),
            benefits: new \App\Core\Workforce\DTOs\BenefitResult(0, 0, 0, []),
            deductions: new \App\Core\Workforce\DTOs\DeductionResult(0, []),
            net: new \App\Core\Workforce\DTOs\NetResult(0, 0, 0),
            blockingAnomalies: [],
            rulesApplied: [],
        );

        // netSocialCents defaults to null
        $this->assertNull($result->netSocialCents);
        $this->assertNull($result->reliefs);

        // toArray still includes relief_lines with zero default
        $arr = $result->toArray();
        $this->assertNull($arr['net_social_cents']);
        $this->assertEquals(['total_employer_relief_cents' => 0, 'lines' => []], $arr['relief_lines']);
    }

    // ──────────────────────────────────────
    // T7: CalculationResult with net social + reliefs via toArray
    // ──────────────────────────────────────
    public function test_T7_calculation_result_with_net_social_and_reliefs(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $gross = 350000;
        $contributions = ContributionCalculator::compute($gross, $plafond, $csgMultiplier, $rules);
        $netSocial = NetSocialCalculator::compute($gross, $contributions);
        $reliefs = ReliefResult::stub();

        $result = new \App\Core\Workforce\DTOs\CalculationResult(
            ruleVersion: 'test-v2',
            plafondSSMonthlyCents: $plafond,
            grossTotalCents: $gross,
            contributions: $contributions,
            tax: new \App\Core\Workforce\DTOs\TaxResult(0, 0, 0),
            benefits: new \App\Core\Workforce\DTOs\BenefitResult(0, 0, 0, []),
            deductions: new \App\Core\Workforce\DTOs\DeductionResult(0, []),
            net: new \App\Core\Workforce\DTOs\NetResult(0, 0, 0),
            blockingAnomalies: [],
            rulesApplied: [],
            ruleSnapshot: null,
            regularizationSnapshot: null,
            netSocialCents: $netSocial,
            reliefs: $reliefs,
        );

        // Net social must be computed
        $this->assertNotNull($result->netSocialCents);
        $this->assertGreaterThan(0, $result->netSocialCents);
        $this->assertLessThan($gross, $result->netSocialCents);

        // Reliefs must be present (stub with zero)
        $this->assertNotNull($result->reliefs);
        $this->assertEquals(0, $result->reliefs->totalEmployerReliefCents);
        $this->assertEmpty($result->reliefs->lines);

        // toArray includes both
        $arr = $result->toArray();
        $this->assertArrayHasKey('net_social_cents', $arr);
        $this->assertArrayHasKey('relief_lines', $arr);
        $this->assertEquals($result->netSocialCents, $arr['net_social_cents']);
        $this->assertEquals(0, $arr['relief_lines']['total_employer_relief_cents']);
    }

    // ──────────────────────────────────────
    // T8: Net social differs from net payable (formula verification)
    // ──────────────────────────────────────
    public function test_T8_net_social_differs_from_net_payable(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $gross = 350000;
        $contributions = ContributionCalculator::compute($gross, $plafond, $csgMultiplier, $rules);
        $netSocial = NetSocialCalculator::compute($gross, $contributions);

        // Net payable = gross - total employee contributions - tax
        $netPayable = $gross - $contributions->totalEmployeeCents; // simplified (no tax in this test)

        // Net social = gross - total employee + CSG non-déductible + CRDS
        // Net social > net payable because CSG ND + CRDS are re-added
        $this->assertNotEquals($netPayable, $netSocial, 'Net social should differ from net payable');
        $this->assertGreaterThan($netPayable, $netSocial, 'Net social should be higher than net payable');

        // The difference should be exactly CSG non-déductible + CRDS
        $crds = 0;
        foreach ($contributions->lines as $line) {
            if ($line->code === 'crds') {
                $crds = $line->employeeCents;
                break;
            }
        }
        $expectedDelta = $contributions->csgNonDeductibleCents + $crds;
        $this->assertEquals($expectedDelta, $netSocial - $netPayable);
    }

    // ──────────────────────────────────────
    // T9: Relief stub does NOT impact net calculations
    // ──────────────────────────────────────
    public function test_T9_relief_stub_does_not_impact_net(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $contributions = ContributionCalculator::compute(350000, $plafond, $csgMultiplier, $rules);
        $relief = ReliefResult::stub();

        // Relief stub has zero — employer total unchanged
        $this->assertEquals(0, $relief->totalEmployerReliefCents);
        $this->assertEquals(
            $contributions->totalEmployerCents,
            $contributions->totalEmployerCents - $relief->totalEmployerReliefCents,
            'Stub relief should not change employer cost'
        );
    }

    // ──────────────────────────────────────
    // T10: Net social on seeded rules (full 20-rule case)
    // ──────────────────────────────────────
    public function test_T10_net_social_with_20_seeded_rules(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $contributions = ContributionCalculator::compute(350000, $plafond, $csgMultiplier, $rules);
        $netSocial = NetSocialCalculator::compute(350000, $contributions);

        // Verify structure
        $this->assertGreaterThan(0, $netSocial);
        $this->assertLessThan(350000, $netSocial);

        // Verify CRDS was found and re-added
        $crdsLine = null;
        foreach ($contributions->lines as $line) {
            if ($line->code === 'crds') {
                $crdsLine = $line;
                break;
            }
        }
        $this->assertNotNull($crdsLine, 'CRDS line should exist in 20-rule set');
        $this->assertGreaterThan(0, $crdsLine->employeeCents);

        // Manual verification: Brut - total employee + CSG ND + CRDS
        $manualNetSocial = 350000
            - $contributions->totalEmployeeCents
            + $contributions->csgNonDeductibleCents
            + $crdsLine->employeeCents;

        $this->assertEquals($manualNetSocial, $netSocial);
    }
}
