<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Workforce\BulletinGroup;
use App\Core\Workforce\Services\ContributionCalculator;
use App\Core\Workforce\Services\PayrollRuleResolver;
use Carbon\Carbon;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 5.1 — Data model foundations for official payslip.
 * Tests: bulletin_group on rules, SIRET/NAF on Company, backward compat.
 */
class BulletinGroupDataModelTest extends TestCase
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
    // T1: BulletinGroup constants
    // ──────────────────────────────────────
    public function test_T1_bulletin_group_has_7_valid_groups(): void
    {
        $this->assertCount(7, BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('sante', BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('at_mp', BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('retraite', BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('famille', BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('chomage', BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('csg_crds', BulletinGroup::DISPLAY_ORDER);
        $this->assertContains('autres_employeur', BulletinGroup::DISPLAY_ORDER);
    }

    public function test_T2_bulletin_group_labels_exist_for_all_groups(): void
    {
        foreach (BulletinGroup::DISPLAY_ORDER as $group) {
            $this->assertTrue(BulletinGroup::isValid($group), "Group '{$group}' should be valid");
            $label = BulletinGroup::label($group);
            $this->assertNotEmpty($label, "Label for '{$group}' should not be empty");
            $this->assertNotEquals($group, $label, "Label for '{$group}' should differ from code");
        }
    }

    // ──────────────────────────────────────
    // T3-T5: Seeded rules have bulletin_group
    // ──────────────────────────────────────
    public function test_T3_all_seeded_contribution_rules_have_bulletin_group(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $rules = MarketRuleSet::where('market_key', 'FR')
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'like', 'contribution_%')
            ->get();

        $this->assertGreaterThanOrEqual(20, $rules->count(), 'Expected at least 20 contribution rules');

        foreach ($rules as $rule) {
            $value = $rule->value;
            $this->assertArrayHasKey('bulletin_group', $value, "Rule {$rule->rule_key} missing bulletin_group");
            $this->assertTrue(
                BulletinGroup::isValid($value['bulletin_group']),
                "Rule {$rule->rule_key} has invalid bulletin_group: {$value['bulletin_group']}"
            );
        }
    }

    public function test_T4_all_7_bulletin_groups_are_covered_by_seeded_rules(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $rules = MarketRuleSet::where('market_key', 'FR')
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'like', 'contribution_%')
            ->get();

        $groups = $rules->pluck('value.bulletin_group')->unique()->sort()->values()->toArray();

        foreach (BulletinGroup::DISPLAY_ORDER as $expectedGroup) {
            $this->assertContains(
                $expectedGroup,
                $groups,
                "Bulletin group '{$expectedGroup}' not covered by any seeded rule"
            );
        }
    }

    public function test_T5_new_contribution_codes_are_seeded(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $expectedNewCodes = [
            'at_mp', 'ceg_t1', 'ceg_t2', 'cet',
            'allocations_familiales', 'ags',
            'fnal', 'csa', 'formation_pro', 'taxe_apprentissage', 'dialogue_social',
        ];

        foreach ($expectedNewCodes as $code) {
            $rule = MarketRuleSet::where('rule_key', "contribution_{$code}")->first();
            $this->assertNotNull($rule, "Rule contribution_{$code} not found in seeder");
            $this->assertEquals($code, $rule->value['code']);
        }
    }

    // ──────────────────────────────────────
    // T6: bulletin_group flows through resolver
    // ──────────────────────────────────────
    public function test_T6_resolver_passes_bulletin_group_through(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));

        $this->assertNotEmpty($rules);

        foreach ($rules as $rule) {
            $this->assertArrayHasKey('bulletin_group', $rule, "Resolved rule {$rule['code']} missing bulletin_group");
            $this->assertTrue(
                BulletinGroup::isValid($rule['bulletin_group']),
                "Resolved rule {$rule['code']} has invalid bulletin_group"
            );
        }
    }

    // ──────────────────────────────────────
    // T7: Backward compat — calculator ignores bulletin_group
    // ──────────────────────────────────────
    public function test_T7_calculator_ignores_bulletin_group_field(): void
    {
        $rulesWithGroup = [
            [
                'code' => 'test_maladie', 'label' => 'Test Maladie', 'category' => 'urssaf',
                'bulletin_group' => 'sante', 'base_type' => 'deplafonne',
                'employee_rate_bps' => 0, 'employer_rate_bps' => 700,
            ],
            [
                'code' => 'test_vieillesse', 'label' => 'Test Vieillesse', 'category' => 'urssaf',
                'bulletin_group' => 'retraite', 'base_type' => 'plafonne_ss',
                'employee_rate_bps' => 690, 'employer_rate_bps' => 855,
            ],
        ];

        $rulesWithoutGroup = array_map(function ($r) {
            unset($r['bulletin_group']);

            return $r;
        }, $rulesWithGroup);

        $gross = 350000;
        $plafond = 383400;
        $csgMultiplier = 9825;

        $resultWith = ContributionCalculator::compute($gross, $plafond, $csgMultiplier, $rulesWithGroup);
        $resultWithout = ContributionCalculator::compute($gross, $plafond, $csgMultiplier, $rulesWithoutGroup);

        $this->assertEquals($resultWithout->totalEmployeeCents, $resultWith->totalEmployeeCents);
        $this->assertEquals($resultWithout->totalEmployerCents, $resultWith->totalEmployerCents);
        $this->assertCount(count($resultWithout->lines), $resultWith->lines);
    }

    // ──────────────────────────────────────
    // T8: Employer-only new codes
    // ──────────────────────────────────────
    public function test_T8_new_employer_only_codes_zero_employee_contribution(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $employerOnlyCodes = [
            'at_mp', 'allocations_familiales', 'ags',
            'fnal', 'csa', 'formation_pro', 'taxe_apprentissage', 'dialogue_social',
        ];

        foreach ($employerOnlyCodes as $code) {
            $rule = MarketRuleSet::where('rule_key', "contribution_{$code}")->first();
            $this->assertEquals(0, $rule->value['employee_rate_bps'], "Code {$code} should have 0 employee rate");
            $this->assertGreaterThan(0, $rule->value['employer_rate_bps'], "Code {$code} should have positive employer rate");
        }
    }

    // ──────────────────────────────────────
    // T9-T10: Company SIRET/NAF
    // ──────────────────────────────────────
    public function test_T9_company_siret_and_naf_can_be_stored(): void
    {
        $company = Company::create([
            'name' => 'SIRET Test Co', 'slug' => 'siret-test',
            'siret' => '12345678901234', 'naf_code' => '6201Z',
            'jobdomain_key' => 'logistique',
        ]);

        $this->assertEquals('12345678901234', $company->fresh()->siret);
        $this->assertEquals('6201Z', $company->fresh()->naf_code);
    }

    public function test_T10_company_siret_and_naf_are_nullable(): void
    {
        $company = Company::create([
            'name' => 'No SIRET Co', 'slug' => 'no-siret',
            'jobdomain_key' => 'logistique',
        ]);

        $this->assertNull($company->siret);
        $this->assertNull($company->naf_code);

        $company->update(['siret' => '98765432109876', 'naf_code' => '4941A']);
        $this->assertEquals('98765432109876', $company->fresh()->siret);
        $this->assertEquals('4941A', $company->fresh()->naf_code);
    }

    // ──────────────────────────────────────
    // T11: Seeder idempotence
    // ──────────────────────────────────────
    public function test_T11_seeder_is_idempotent(): void
    {
        $seeder = new WorkforcePayrollRuleSeeder;
        $seeder->run();
        $countFirst = MarketRuleSet::where('domain', 'workforce_payroll')->count();

        $seeder->run();
        $countSecond = MarketRuleSet::where('domain', 'workforce_payroll')->count();

        $this->assertEquals($countFirst, $countSecond, 'Seeder should be idempotent (updateOrCreate)');
    }

    // ──────────────────────────────────────
    // T12: Full calculation with all 20 rules
    // ──────────────────────────────────────
    public function test_T12_full_calculation_with_20_rules_produces_valid_result(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $resolver = app(PayrollRuleResolver::class);
        $rules = $resolver->resolveContributions('FR', Carbon::parse('2026-06-15'));
        $plafond = $resolver->resolvePlafondSS('FR', Carbon::parse('2026-06-15'));
        $csgMultiplier = $resolver->resolveCsgBaseMultiplier('FR', Carbon::parse('2026-06-15'));

        $gross = 350000; // €3500

        $result = ContributionCalculator::compute($gross, $plafond, $csgMultiplier, $rules);

        $this->assertGreaterThan(0, $result->totalEmployeeCents, 'Employee total should be positive');
        $this->assertGreaterThan(0, $result->totalEmployerCents, 'Employer total should be positive');
        $this->assertGreaterThan($result->totalEmployeeCents, $result->totalEmployerCents, 'Employer total should exceed employee total');
        $this->assertCount(20, $result->lines, 'Should have 20 contribution lines');

        $groupsInRules = collect($rules)->pluck('bulletin_group')->unique()->sort()->values()->toArray();
        $this->assertCount(7, $groupsInRules);
    }

    // ──────────────────────────────────────
    // T13: Original 9 rules rates unchanged
    // ──────────────────────────────────────
    public function test_T13_original_9_rules_rates_unchanged(): void
    {
        $this->seed(WorkforcePayrollRuleSeeder::class);

        $expectedRates = [
            'urssaf_maladie' => [0, 700],
            'urssaf_vieillesse_plaf' => [690, 855],
            'urssaf_vieillesse_deplaf' => [40, 190],
            'chomage' => [0, 405],
            'retraite_t1' => [386, 604],
            'retraite_t2' => [862, 1362],
            'csg_deductible' => [681, 0],
            'csg_non_deductible' => [242, 0],
            'crds' => [50, 0],
        ];

        foreach ($expectedRates as $code => [$empRate, $erRate]) {
            $rule = MarketRuleSet::where('rule_key', "contribution_{$code}")->first();
            $this->assertNotNull($rule, "Rule contribution_{$code} not found");
            $this->assertEquals($empRate, $rule->value['employee_rate_bps'], "Employee rate changed for {$code}");
            $this->assertEquals($erRate, $rule->value['employer_rate_bps'], "Employer rate changed for {$code}");
        }
    }
}
