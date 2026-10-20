<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Policies\CompanyPolicy;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workforce Rules Engine (mission §2) — explainable break-rule resolution over the EXISTING
 * stores (MarketRuleSet legal baseline + CompanyPolicy company overrides). Covers provenance,
 * mutability, legal bounds, effective dates, operational defaults, to_complete, and per-Company
 * isolation. No hardcoded legal values — the legal minimum comes from the seeded MarketRuleSet.
 */
class WorkforceRuleResolverTest extends TestCase
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

    public function test_legal_break_minimum_is_mandatory_and_sourced_from_market(): void
    {
        $spec = $this->resolver->resolveBreakRules($this->company('co-legal'));

        $this->assertSame(20, $spec['min_duration_minutes']['value'], 'FR legal min break = 20 min (L3121-16)');
        $this->assertSame('market_law', $spec['min_duration_minutes']['source']);
        $this->assertSame('mandatory', $spec['min_duration_minutes']['mutability']);
        $this->assertStringContainsString('L3121-16', (string) $spec['min_duration_minutes']['reference']);

        $this->assertSame(360, $spec['trigger_after_minutes']['value'], 'break triggered after 6h = 360 min');
        $this->assertSame('mandatory', $spec['trigger_after_minutes']['mutability']);
    }

    public function test_target_defaults_to_legal_minimum_then_follows_company_override(): void
    {
        $company = $this->company('co-target');

        // No override → target defaults to the legal minimum, tagged as a default (not a legal claim).
        $spec = $this->resolver->resolveBreakRules($company);
        $this->assertSame(20, $spec['target_duration_minutes']['value']);
        $this->assertSame('default', $spec['target_duration_minutes']['source']);
        $this->assertSame('configurable', $spec['target_duration_minutes']['mutability']);

        // Admin configures a 30-min target.
        $this->resolver->setBreakRule($company, 'target_duration_minutes', 30);
        $spec = $this->resolver->resolveBreakRules($company->fresh());
        $this->assertSame(30, $spec['target_duration_minutes']['value']);
        $this->assertSame('company', $spec['target_duration_minutes']['source']);
        $this->assertSame('configured', $spec['target_duration_minutes']['status']);
        $this->assertSame(30, $this->resolver->breakTargetMinutes($company->fresh()));
    }

    public function test_cannot_configure_target_below_legal_minimum(): void
    {
        $this->expectException(\DomainException::class);
        $this->resolver->setBreakRule($this->company('co-floor'), 'target_duration_minutes', 10);
    }

    public function test_cannot_override_a_mandatory_legal_rule(): void
    {
        $this->expectException(\DomainException::class);
        $this->resolver->setBreakRule($this->company('co-mand'), 'min_duration_minutes', 45);
    }

    public function test_operational_defaults_are_tagged_default_not_law(): void
    {
        $spec = $this->resolver->resolveBreakRules($this->company('co-default'));

        $this->assertFalse($spec['included_in_worktime']['value']);
        $this->assertSame('default', $spec['included_in_worktime']['source']); // operational, not a legal claim
        $this->assertSame('signaled', $spec['enforcement']['value']);
        $this->assertSame('warn', $spec['early_resume_policy']['value']);
        // An unconfigured optional numeric with no default stays null/default (not invented).
        $this->assertNull($spec['max_count']['value']);
    }

    public function test_company_isolation_of_break_rules(): void
    {
        $a = $this->company('co-a');
        $b = $this->company('co-b');

        $this->resolver->setBreakRule($a, 'target_duration_minutes', 30);
        $this->resolver->setBreakRule($b, 'target_duration_minutes', 45);

        $this->assertSame(30, $this->resolver->breakTargetMinutes($a->fresh()), "A keeps its own target");
        $this->assertSame(45, $this->resolver->breakTargetMinutes($b->fresh()), "B keeps its own target");
    }

    public function test_effective_dating_of_a_company_override(): void
    {
        $company = $this->company('co-dated');

        // Set a 40-min target effective tomorrow; today it must still be the default.
        $this->resolver->setBreakRule($company, 'target_duration_minutes', 40, now()->addDay());

        $this->assertSame(20, $this->resolver->breakTargetMinutes($company->fresh(), now()), 'today = default');
        $this->assertSame(40, $this->resolver->breakTargetMinutes($company->fresh(), now()->addDays(2)), 'future = configured');
    }
}
