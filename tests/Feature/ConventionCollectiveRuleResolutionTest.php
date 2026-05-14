<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Workforce\CompanyPayrollRuleOverride;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\Services\ContributionCalculator;
use App\Core\Workforce\Services\PayrollRuleResolver;
use Carbon\Carbon;
use Database\Seeders\WorkforcePayrollRuleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConventionCollectiveRuleResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected ConventionCollective $cc;
    protected PayrollRuleResolver $resolver;
    protected Carbon $periodEnd;

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

        $this->company = Company::create([
            'name' => 'CC Test Co',
            'slug' => 'cc-test-co',
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

        $this->resolver = app(PayrollRuleResolver::class);
        $this->periodEnd = Carbon::parse('2026-05-31');
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    // ── Helper ──

    private function createCCRule(array $overrides = []): ConventionCollectiveRule
    {
        return ConventionCollectiveRule::create(array_merge([
            'convention_collective_id' => $this->cc->id,
            'domain' => 'workforce_payroll',
            'rule_type' => 'contribution',
            'rule_key' => 'contribution_cc_mutuelle',
            'value' => [
                'code' => 'cc_mutuelle',
                'label' => 'Mutuelle CC',
                'category' => 'complementaire',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 50,
                'employer_rate_bps' => 100,
            ],
            'effective_from' => '2026-01-01',
            'override_mode' => 'supplement',
            'source' => 'CC Métallurgie Art. 72',
        ], $overrides));
    }

    private function createCompanyOverride(array $overrides = []): CompanyPayrollRuleOverride
    {
        return CompanyPayrollRuleOverride::create(array_merge([
            'company_id' => $this->company->id,
            'domain' => 'workforce_payroll',
            'rule_type' => 'contribution',
            'rule_key' => 'contribution_urssaf_maladie',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Assurance maladie (accord entreprise)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 600,
            ],
            'effective_from' => '2026-01-01',
            'override_mode' => 'replace',
            'approved_by' => 42,
            'justification' => 'Accord CSE 2026',
            'source' => 'Accord entreprise 2026-03',
        ], $overrides));
    }

    // ── 1. Backward compatibility: resolveContributions() inchangé ──

    public function test_resolve_contributions_unchanged(): void
    {
        $rules = $this->resolver->resolveContributions('FR', $this->periodEnd);

        $this->assertCount(20, $rules);

        // Each rule has the original structure (no source_level, no resolution_chain)
        foreach ($rules as $rule) {
            $this->assertArrayHasKey('rule_key', $rule);
            $this->assertArrayHasKey('source', $rule);
            $this->assertArrayNotHasKey('source_level', $rule);
            $this->assertArrayNotHasKey('resolution_chain', $rule);
        }
    }

    // ── 2. Fallback market si pas de CC ──

    public function test_resolve_without_cc_returns_market_rules_only(): void
    {
        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd);

        $this->assertCount(20, $rules);

        foreach ($rules as $rule) {
            $this->assertEquals('market_law', $rule['source_level']);
            $this->assertEquals(['market_law'], $rule['resolution_chain']);
        }
    }

    // ── 3. CC replace: remplace la règle légale ──

    public function test_cc_replace_overrides_market_rule(): void
    {
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'replace',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Assurance maladie (CC)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 650, // CC rate differs from legal 700
            ],
        ]);

        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);

        // Still 9 rules — the CC replaced the market one
        $this->assertCount(20, $rules);

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertNotNull($maladie);
        $this->assertEquals(650, $maladie['employer_rate_bps']);
        $this->assertEquals('convention_collective', $maladie['source_level']);
        $this->assertEquals('replace', $maladie['override_mode']);
        $this->assertContains('convention_collective', $maladie['resolution_chain']);
    }

    // ── 4. CC supplement: ajoute une règle additionnelle ──

    public function test_cc_supplement_adds_additional_rule(): void
    {
        $this->createCCRule(); // default: supplement mutuelle

        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);

        // 9 market + 1 CC supplement = 10
        $this->assertCount(21, $rules);

        $mutuelle = collect($rules)->first(fn ($r) => str_contains($r['rule_key'], 'mutuelle'));
        $this->assertNotNull($mutuelle);
        $this->assertEquals('convention_collective', $mutuelle['source_level']);
        $this->assertEquals('supplement', $mutuelle['override_mode']);
        $this->assertEquals(50, $mutuelle['employee_rate_bps']);
        $this->assertEquals(100, $mutuelle['employer_rate_bps']);
    }

    // ── 5. CC minimum: keeps most favorable ──

    public function test_cc_minimum_keeps_higher_rate(): void
    {
        // Market maladie: employer 700, employee 0 → total 700
        // CC maladie minimum with total 900
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'minimum',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Assurance maladie (CC minimum)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 900, // CC is more favorable: 900 > 700
            ],
        ]);

        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals(900, $maladie['employer_rate_bps']);
        $this->assertEquals('convention_collective', $maladie['source_level']);
    }

    public function test_cc_minimum_keeps_market_when_more_favorable(): void
    {
        // Market maladie: employer 700 → total 700
        // CC maladie minimum with total 500 → market wins
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'minimum',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Assurance maladie (CC bas)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 500, // CC less favorable: 500 < 700
            ],
        ]);

        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals(700, $maladie['employer_rate_bps']);
        $this->assertEquals('market_law', $maladie['source_level']);
    }

    // ── 6. Company override > CC (replace) ──

    public function test_company_override_replaces_cc_rule(): void
    {
        // CC replaces maladie with 650
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'replace',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Assurance maladie (CC)',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 650,
            ],
        ]);

        // Company override replaces with 600
        $this->createCompanyOverride();

        $rules = $this->resolver->resolveContributionsWithCC(
            'FR', $this->periodEnd, $this->cc->id, $this->company->id
        );

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals(600, $maladie['employer_rate_bps']);
        $this->assertEquals('company_override', $maladie['source_level']);
        $this->assertContains('company_override', $maladie['resolution_chain']);
    }

    // ── 7. Company override minimum vs CC ──

    public function test_company_override_minimum_keeps_higher(): void
    {
        // CC replaces maladie with 800
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'replace',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Maladie CC',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 800,
            ],
        ]);

        // Company override minimum with 600 → CC wins (800 > 600)
        $this->createCompanyOverride([
            'override_mode' => 'minimum',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Maladie accord',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 600,
            ],
        ]);

        $rules = $this->resolver->resolveContributionsWithCC(
            'FR', $this->periodEnd, $this->cc->id, $this->company->id
        );

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        // CC had 800, company override minimum 600 → 800 wins
        $this->assertEquals(800, $maladie['employer_rate_bps']);
        $this->assertEquals('convention_collective', $maladie['source_level']);
    }

    // ── 8. Expired CC rule ignored ──

    public function test_expired_cc_rule_is_ignored(): void
    {
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'replace',
            'effective_from' => '2025-01-01',
            'effective_until' => '2025-12-31', // expired before 2026-05-31
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Maladie CC expiré',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 999,
            ],
        ]);

        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);

        // Still 9 market rules, CC expired rule ignored
        $this->assertCount(20, $rules);

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals(700, $maladie['employer_rate_bps']); // market rate
        $this->assertEquals('market_law', $maladie['source_level']);
    }

    // ── 9. Contract A with CC ≠ Contract B without CC ──

    public function test_cc_isolation_between_contracts(): void
    {
        $this->createCCRule([
            'rule_key' => 'contribution_urssaf_maladie',
            'override_mode' => 'replace',
            'value' => [
                'code' => 'urssaf_maladie',
                'label' => 'Maladie CC',
                'category' => 'urssaf',
                'base_type' => 'deplafonne',
                'employee_rate_bps' => 0,
                'employer_rate_bps' => 650,
            ],
        ]);

        // Contract A: with CC
        $rulesA = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);
        $maladieA = collect($rulesA)->firstWhere('rule_key', 'contribution_urssaf_maladie');

        // Contract B: without CC (null)
        $rulesB = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, null);
        $maladieB = collect($rulesB)->firstWhere('rule_key', 'contribution_urssaf_maladie');

        $this->assertEquals(650, $maladieA['employer_rate_bps']);
        $this->assertEquals('convention_collective', $maladieA['source_level']);

        $this->assertEquals(700, $maladieB['employer_rate_bps']);
        $this->assertEquals('market_law', $maladieB['source_level']);
    }

    // ── 10. Snapshot contains CC context ──

    public function test_snapshot_contains_cc_context(): void
    {
        $this->createCCRule(); // supplement mutuelle

        $snapshot = $this->resolver->snapshotWithCC('FR', $this->periodEnd, $this->cc->id);

        $this->assertEquals('payroll-resolver-v2', $snapshot['resolver_version']);
        $this->assertNotNull($snapshot['convention_collective']);
        $this->assertEquals($this->cc->id, $snapshot['convention_collective']['id']);
        $this->assertEquals('3248', $snapshot['convention_collective']['idcc']);
        $this->assertEquals('Métallurgie', $snapshot['convention_collective']['name']);
        $this->assertEquals('3109', $snapshot['convention_collective']['brochure_number']);
        $this->assertEquals(1, $snapshot['convention_collective']['rules_applied_count']);
        $this->assertContains('supplement', $snapshot['convention_collective']['override_modes_used']);
        $this->assertNull($snapshot['company_override']);

        // Rules include the supplemented one (20 market + 1 CC supplement)
        $this->assertEquals(21, $snapshot['contribution_rules_count']);
    }

    // ── 11. Snapshot contains company override context ──

    public function test_snapshot_contains_company_override_context(): void
    {
        $this->createCompanyOverride();

        $snapshot = $this->resolver->snapshotWithCC(
            'FR', $this->periodEnd, null, $this->company->id
        );

        $this->assertNull($snapshot['convention_collective']);
        $this->assertNotNull($snapshot['company_override']);
        $this->assertEquals($this->company->id, $snapshot['company_override']['company_id']);
        $this->assertEquals(1, $snapshot['company_override']['rules_applied_count']);

        $overrideRule = $snapshot['company_override']['rules_applied'][0];
        $this->assertEquals('contribution_urssaf_maladie', $overrideRule['rule_key']);
        $this->assertEquals(42, $overrideRule['approved_by']);
        $this->assertEquals('Accord CSE 2026', $overrideRule['justification']);
    }

    // ── 12. Snapshot sufficient to recalculate without DB ──

    public function test_snapshot_enables_offline_recalculation(): void
    {
        $this->createCCRule(); // supplement mutuelle

        $snapshot = $this->resolver->snapshotWithCC('FR', $this->periodEnd, $this->cc->id);

        // Extract contribution rules from snapshot
        $rulesFromSnapshot = $snapshot['contribution_rules'];
        $plafondSS = $snapshot['plafond_ss_monthly_cents'];
        $csgMultiplier = $snapshot['csg_base_multiplier_bps'];

        // Compute using ContributionCalculator (pure function)
        $grossCents = 350000;
        $result = ContributionCalculator::compute($grossCents, $plafondSS, $csgMultiplier, $rulesFromSnapshot);

        // The snapshot data was sufficient to produce a result — no DB needed
        $this->assertGreaterThan(0, $result->totalEmployeeCents);
        $this->assertGreaterThan(0, $result->totalEmployerCents);
        $this->assertCount(21, $result->lines); // 20 market + 1 CC supplement
    }

    // ── 13. Company override without CC applies directly on market ──

    public function test_company_override_without_cc(): void
    {
        $this->createCompanyOverride();

        $rules = $this->resolver->resolveContributionsWithCC(
            'FR', $this->periodEnd, null, $this->company->id
        );

        $maladie = collect($rules)->firstWhere('rule_key', 'contribution_urssaf_maladie');
        $this->assertEquals(600, $maladie['employer_rate_bps']);
        $this->assertEquals('company_override', $maladie['source_level']);
        // Resolution chain: market → company (no CC)
        $this->assertEquals(
            ['market_law', 'convention_collective', 'company_override'],
            $maladie['resolution_chain']
        );
    }

    // ── 14. Only contribution and benefit rule_types resolved ──

    public function test_only_contribution_and_benefit_rule_types_resolved(): void
    {
        // Create a CC rule with rule_type = 'seniority_bonus' (not resolved in 4.1)
        $this->createCCRule([
            'rule_type' => 'seniority_bonus',
            'rule_key' => 'seniority_bonus_cc',
            'value' => [
                'code' => 'seniority',
                'label' => 'Prime ancienneté',
                'tiers' => [['min_years' => 3, 'rate_bps' => 200]],
            ],
        ]);

        $rules = $this->resolver->resolveContributionsWithCC('FR', $this->periodEnd, $this->cc->id);

        // Only 9 market rules, seniority_bonus is not resolved
        $this->assertCount(20, $rules);
        $this->assertNull(collect($rules)->firstWhere('rule_key', 'seniority_bonus_cc'));
    }

    // ── 15. snapshot() (v1) remains unchanged ──

    public function test_snapshot_v1_unchanged(): void
    {
        $snapshot = $this->resolver->snapshot('FR', $this->periodEnd);

        $this->assertArrayHasKey('market_key', $snapshot);
        $this->assertArrayHasKey('resolved_at', $snapshot);
        $this->assertArrayHasKey('plafond_ss_monthly_cents', $snapshot);
        $this->assertArrayHasKey('csg_base_multiplier_bps', $snapshot);
        $this->assertArrayHasKey('contribution_rules_count', $snapshot);
        $this->assertArrayHasKey('contribution_rules', $snapshot);

        // V1 does NOT have resolver_version, convention_collective, company_override
        $this->assertArrayNotHasKey('resolver_version', $snapshot);
        $this->assertArrayNotHasKey('convention_collective', $snapshot);
        $this->assertArrayNotHasKey('company_override', $snapshot);
    }
}
