<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT E — normative, multi-dimension resolution (Market → CC → JobDomain → Company → Contract).
 * Proves it is NOT a blind override chain: legal floors/ceilings, more-favorable comparison,
 * less-favorable values ignored + flagged, contract CC precedence, provenance + chain, work-time.
 */
class WorkforceNormativeResolutionTest extends TestCase
{
    use RefreshDatabase;

    private WorkforceRuleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        $this->seed(\Database\Seeders\MarketRuleSetSeeder::class);
        $this->resolver = app(WorkforceRuleResolver::class);
    }

    private function company(string $slug, string $market = 'FR'): Company
    {
        return Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => $market]);
    }

    private function cc(string $idcc, array $rules): ConventionCollective
    {
        $cc = ConventionCollective::create(['idcc' => $idcc, 'short_name' => "CC{$idcc}", 'full_name' => "Convention {$idcc}", 'market_key' => 'FR', 'is_active' => true]);
        foreach ($rules as $key => $amount) {
            ConventionCollectiveRule::create([
                'convention_collective_id' => $cc->id, 'domain' => 'workforce', 'rule_type' => 'working_time',
                'rule_key' => $key, 'value' => ['amount' => $amount], 'effective_from' => '2020-01-01',
                'override_mode' => 'minimum', 'source' => "CC{$idcc}", 'reference' => "Art. {$key}",
            ]);
        }

        return $cc;
    }

    public function test_cc_more_favorable_raises_the_legal_minimum_break(): void
    {
        $cc = $this->cc('1000', ['break_min_minutes' => 30]); // CC 30 min > legal 20
        $company = $this->company('co-ccfav');
        $company->update(['default_convention_collective_id' => $cc->id]);

        $spec = $this->resolver->resolveBreakRules($company->fresh());
        $this->assertSame(30, $spec['min_duration_minutes']['value'], 'CC more-favorable floor wins');
        $this->assertSame('convention', $spec['min_duration_minutes']['source']);
        // The chain records both layers for explainability.
        $layers = array_column($spec['min_duration_minutes']['chain'], 'layer');
        $this->assertContains('market_law', $layers);
        $this->assertContains('convention', $layers);
    }

    public function test_cc_less_favorable_is_ignored_and_flagged_as_conflict(): void
    {
        $cc = $this->cc('1001', ['break_min_minutes' => 15]); // CC 15 min < legal 20 → illegal, ignored
        $company = $this->company('co-ccbad');
        $company->update(['default_convention_collective_id' => $cc->id]);

        $spec = $this->resolver->resolveBreakRules($company->fresh());
        $this->assertSame(20, $spec['min_duration_minutes']['value'], 'legal floor holds');
        $this->assertSame('market_law', $spec['min_duration_minutes']['source']);
        $conflicts = $spec['min_duration_minutes']['conflicts'];
        $this->assertNotEmpty($conflicts, 'less-favorable CC value is flagged');
        $this->assertSame(15, $conflicts[0]['ignored_value']);
    }

    public function test_legal_ceiling_daily_hours_only_lowered_by_more_favorable(): void
    {
        // CC lowers the daily max to 8h (more favorable) ; another scenario proposing 12h is ignored.
        $ccLow = $this->cc('1002', ['daily_hours_max' => 8]);
        $a = $this->company('co-ceil-ok');
        $a->update(['default_convention_collective_id' => $ccLow->id]);
        $specA = $this->resolver->resolveWorkTimeRules($a->fresh());
        $this->assertSame(8, $specA['daily_hours_max']['value'], 'CC 8h < legal 10h wins (more favorable)');
        $this->assertSame('convention', $specA['daily_hours_max']['source']);

        $ccHigh = $this->cc('1003', ['daily_hours_max' => 12]);
        $b = $this->company('co-ceil-bad');
        $b->update(['default_convention_collective_id' => $ccHigh->id]);
        $specB = $this->resolver->resolveWorkTimeRules($b->fresh());
        $this->assertSame(10, $specB['daily_hours_max']['value'], 'CC 12h > legal 10h ignored');
        $this->assertSame('market_law', $specB['daily_hours_max']['source']);
        $this->assertNotEmpty($specB['daily_hours_max']['conflicts']);
    }

    public function test_contract_cc_takes_precedence_over_company_default_cc(): void
    {
        $companyCc = $this->cc('2000', ['break_min_minutes' => 25]);
        $contractCc = $this->cc('2001', ['break_min_minutes' => 35]);
        $company = $this->company('co-contract-cc');
        $company->update(['default_convention_collective_id' => $companyCc->id]);

        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $company->id, 'first_name' => 'E', 'last_name' => 'C', 'status' => 'active', 'hire_date' => '2024-01-01']);
        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire',
            'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'convention_collective_id' => $contractCc->id,
        ]);

        // Company default resolves 25 ; the contract's CC resolves 35.
        $this->assertSame(25, $this->resolver->resolveBreakRules($company->fresh())['min_duration_minutes']['value']);
        $this->assertSame(35, $this->resolver->resolveBreakRules($company->fresh(), null, $contract)['min_duration_minutes']['value']);
    }

    public function test_worktime_reference_and_rest_resolve_from_market(): void
    {
        $company = $this->company('co-worktime');
        $spec = $this->resolver->resolveWorkTimeRules($company);

        $this->assertSame(35, $spec['weekly_hours_reference']['value'], 'FR legal weekly reference 35h');
        $this->assertSame(11, $spec['daily_rest_min_hours']['value'], 'FR daily rest 11h (legal_min)');
        $this->assertSame('mandatory', $spec['daily_rest_min_hours']['mutability']);

        // Company can configure the reference down to e.g. 32h (config nature).
        $this->resolver->setWorkTimeRule($company, 'weekly_hours_reference', 32);
        $this->assertSame(32, $this->resolver->resolveWorkTimeRules($company->fresh())['weekly_hours_reference']['value']);
        $this->assertSame('company', $this->resolver->resolveWorkTimeRules($company->fresh())['weekly_hours_reference']['source']);
    }
}
