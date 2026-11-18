<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainComposition;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleDependencyResolver;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-821 — ONE dependency resolution (module manifests) for JobDomain, plans and company activation.
 * Payroll → Leave → Workforce is the real transitive chain.
 */
class Adr821ModuleDependencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.members', 'logistics_shipments', 'workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents']]);
        PlatformModule::whereIn('key', ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents'])->update(['addon_pricing' => null, 'min_plan_override' => null]);
        $this->company = $this->company('A');
    }

    protected function tearDown(): void
    {
        ModuleRegistry::clearCache();
        parent::tearDown();
    }

    private function company(string $tag): Company
    {
        $c = Company::create(['name' => "Dep $tag", 'slug' => 'dep-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'business']);
        $c->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);

        return $c;
    }

    private function inject(array $manifests): void
    {
        $prop = new \ReflectionProperty(ModuleRegistry::class, 'cache');
        $prop->setValue(null, array_merge(ModuleRegistry::definitions(), $manifests));
    }

    private function fake(string $key, array $requires): ModuleManifest
    {
        $base = ModuleRegistry::definitions()['workforce_leave'];

        return new ModuleManifest(key: $key, name: $key, description: '', surface: $base->surface, sortOrder: 999, capabilities: $base->capabilities,
            permissions: [], bundles: [], scope: 'company', type: 'addon', requires: $requires);
    }

    public function test_simple_multiple_and_transitive_dependencies_without_duplicates(): void
    {
        $this->assertSame(['workforce'], ModuleDependencyResolver::requiresOf('workforce_planning'));
        $r = ModuleDependencyResolver::resolve(['workforce_payroll', 'workforce_planning']);
        $this->assertEqualsCanonicalizing(['workforce_payroll', 'workforce_planning', 'workforce_leave', 'workforce'], $r['all']);
        $this->assertSame(['workforce_payroll'], $r['added']['workforce_leave'], 'explains who needs the added module');
        $this->assertContains('workforce_planning', $r['added']['workforce']);
        $this->assertCount(4, array_unique($r['all']), 'no duplicate');
    }

    public function test_cycles_and_unknown_modules_are_detected_and_refused(): void
    {
        $this->inject(['cyc_a' => $this->fake('cyc_a', ['cyc_b']), 'cyc_b' => $this->fake('cyc_b', ['cyc_a']), 'orphan_x' => $this->fake('orphan_x', ['does_not_exist'])]);
        $this->assertNotEmpty(ModuleDependencyResolver::resolve(['cyc_a'])['cycles']);
        $this->assertSame(['does_not_exist'], ModuleDependencyResolver::resolve(['orphan_x'])['unknown']);
        $this->assertFalse(ModuleDependencyResolver::forCompany($this->company, 'orphan_x')['can_activate']);
    }

    public function test_company_activation_preview_then_coherent_activation(): void
    {
        ModuleActivationEngine::enable($this->company, 'workforce');
        $preview = ModuleDependencyResolver::forCompany($this->company, 'workforce_payroll');
        $this->assertSame(['workforce'], $preview['already_active']);
        $this->assertEqualsCanonicalizing(['workforce_payroll', 'workforce_leave'], $preview['to_activate']);
        $this->assertTrue($preview['can_activate']);
        $this->assertTrue(ModuleActivationEngine::enable($this->company, 'workforce_payroll')['success']);
        foreach (['workforce_payroll', 'workforce_leave', 'workforce'] as $k) {
            $this->assertTrue(ModuleGate::isActive($this->company, $k), $k);
        }
    }

    public function test_premium_dependency_never_granted_and_offer_coverage(): void
    {
        // Paid dependency not bought : activation blocked, nothing activated (no partial state).
        PlatformModule::where('key', 'workforce')->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]]]);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.members', 'logistics_shipments']]);
        \App\Core\Billing\CompanyAddonSubscription::create(['company_id' => $this->company->id, 'module_key' => 'workforce_documents', 'interval' => 'monthly',
            'amount_cents' => 0, 'currency' => 'EUR', 'activated_at' => now(), 'deactivated_at' => now()]); // un historique commercial existe : droit non implicite
        $preview = ModuleDependencyResolver::forCompany($this->company, 'workforce_planning');
        $this->assertFalse($preview['can_activate']);
        $this->assertSame(0, CompanyModule::where('company_id', $this->company->id)->where('is_enabled_for_company', true)->whereIn('module_key', ['workforce', 'workforce_planning'])->count());

        // Offer coverage : planning included while its dependency is a paid option = incoherent offer.
        $violations = ModuleDependencyResolver::planCoverage([], ['workforce_planning']);
        $this->assertNotEmpty($violations);
        $this->assertSame('workforce', $violations[0]['dependency']);
        $this->assertSame('option', $violations[0]['dependency_status']);
    }

    public function test_deactivation_refused_with_dependents_then_cascade_and_reactivation(): void
    {
        ModuleActivationEngine::enable($this->company, 'workforce_planning');
        ModuleActivationEngine::enable($this->company, 'workforce_leave');
        $refused = ModuleActivationEngine::disable($this->company, 'workforce');
        $this->assertSame(409, $refused['status']);
        $this->assertEqualsCanonicalizing(['workforce_planning', 'workforce_leave'], $refused['data']['dependents'], 'module used by several others');
        $this->assertTrue(ModuleGate::isActive($this->company, 'workforce_planning'));
        $this->assertTrue(ModuleActivationEngine::disable($this->company, 'workforce', true)['success']);
        $this->assertSame(0, CompanyModule::where('company_id', $this->company->id)->where('is_enabled_for_company', true)
            ->whereIn('module_key', ['workforce', 'workforce_planning', 'workforce_leave'])->count(), 'cache synced for cascaded dependents');
        foreach (['workforce', 'workforce_planning', 'workforce_leave'] as $k) {
            $this->assertFalse(ModuleGate::isActive($this->company, $k), $k);
        }
        $this->assertTrue(ModuleActivationEngine::enable($this->company, 'workforce_planning')['success'], 'reactivation');
        $this->assertTrue(ModuleGate::isActive($this->company, 'workforce'));
    }

    public function test_transaction_failure_leaves_no_partial_activation(): void
    {
        DB::listen(function ($q) {
            if (str_contains($q->sql, 'company_module_activation_reasons') && str_contains(strtolower($q->sql), 'insert') && str_contains(json_encode($q->bindings), 'workforce_leave')) {
                throw new \RuntimeException('simulated failure');
            }
        });
        try {
            ModuleActivationEngine::enable($this->company, 'workforce_payroll');
            $this->fail('failure expected');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, CompanyModule::where('company_id', $this->company->id)->where('is_enabled_for_company', true)
            ->whereIn('module_key', ['workforce', 'workforce_leave', 'workforce_payroll'])->count(), 'all or nothing');
    }

    public function test_jobdomain_composition_adds_dependencies_and_keeps_historical_rights(): void
    {
        $jd = Jobdomain::where('key', 'logistique')->first();
        $this->assertTrue(ModuleActivationEngine::enable($this->company, 'logistics_shipments')['success']);
        $compatibleNow = array_values(array_map(fn ($m) => $m['key'], array_filter(JobdomainComposition::describe($jd)['modules'], fn ($m) => $m['compatible'])));
        $out = JobdomainComposition::save($jd, ['compatible' => $compatibleNow, 'start' => ['workforce_planning'], 'recommended' => ['workforce_payroll']], null);
        $this->assertSame(['workforce_planning'], $out['added']['workforce']);
        $jd->refresh();
        $this->assertContains('workforce', $jd->default_modules, 'dependency added to the start modules');
        $this->assertEqualsCanonicalizing(['workforce_payroll', 'workforce_leave'], $jd->recommended_modules);
        $this->assertTrue(JobdomainComposition::isCompatible('workforce_planning', 'logistique'));

        // Un module actif dans une société de ce métier ne peut pas perdre sa compatibilité (droit historique).
        $this->expectException(ValidationException::class);
        $all = array_map(fn ($m) => $m['key'], JobdomainComposition::describe($jd)['modules']);
        JobdomainComposition::save($jd, ['compatible' => array_values(array_diff($all, ['logistics_shipments'])), 'start' => [], 'recommended' => []], null);
    }

    public function test_offer_edit_creating_an_uncovered_dependency_is_refused_and_plan_shows_it(): void
    {
        $admin = \App\Platform\Models\PlatformUser::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $admin->roles()->attach(\App\Platform\Models\PlatformRole::where('key', 'super_admin')->firstOrFail());
        // Planning est inclus dans toutes les offres : rendre sa dépendance (RH) payante créerait une offre incohérente.
        $this->actingAs($admin, 'platform')->putJson('/api/platform/modules/workforce/config', ['is_listed' => true, 'is_sellable' => true, 'addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]]])
            ->assertStatus(422)->assertJsonValidationErrors('min_plan_override');
        $this->assertNull(PlatformModule::where('key', 'workforce')->value('addon_pricing'), 'nothing saved');
        $plan = $this->actingAs($admin, 'platform')->getJson('/api/platform/plans/starter/modules')->assertOk()->json();
        $row = collect($plan['modules'])->firstWhere('key', 'workforce_payroll');
        $this->assertSame('included', $row['status']);
        $this->assertEqualsCanonicalizing(['workforce', 'workforce_leave'], array_column($row['dependencies'], 'key'), 'transitive dependencies shown');
        $this->assertTrue($plan['coherent']);
    }

    /** ADR-822 — Referral needs HR : while HR is a paid option, Referral is never « included » nor activated without HR. */
    public function test_referral_is_included_only_with_its_paid_dependency(): void
    {
        PlatformModule::where('key', 'workforce')->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]]]);
        foreach (\App\Core\Plans\PlanRegistry::keys() as $plan) {
            $st = ModuleDependencyResolver::commercialStatus('workforce_referral', $plan);
            $this->assertNotSame('included', $st, "never presented as included ($plan)");
        }
        $this->assertSame('with_dependency', ModuleDependencyResolver::commercialStatus('workforce_referral', 'business'));
        $gap = collect(ModuleDependencyResolver::planCoverage())->where('module', 'workforce_referral')->first();
        $this->assertSame('workforce', $gap['dependency'], 'reported as a gap needing a pricing decision');

        $preview = ModuleDependencyResolver::forCompany($this->company, 'workforce_referral');
        $this->assertContains('workforce', $preview['purchase'], 'activating Referral announces the HR purchase');
        $this->assertNotContains('workforce_referral', $preview['purchase'], 'Referral itself has no price');
    }

    public function test_paid_option_listed_by_the_business_is_never_given_away_but_historical_rights_stay(): void
    {
        $historical = $this->company('H');
        ModuleActivationEngine::enable($historical, 'workforce'); // actif AVANT que le module devienne payant
        PlatformModule::where('key', 'workforce')->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]]]);
        $e = \App\Core\Modules\EntitlementResolver::check($this->company, 'workforce');
        $this->assertNotSame('jobdomain', $e['source'], 'a paid option is not granted by the business list');
        $this->assertTrue(ModuleGate::isActive($historical, 'workforce'), 'historical right kept');
        $this->assertSame('jobdomain', \App\Core\Modules\EntitlementResolver::check($historical, 'workforce')['source']);
    }

    public function test_preview_endpoints_are_tenant_scoped_and_guarded(): void
    {
        $other = $this->company('B');
        ModuleActivationEngine::enable($other, 'workforce_planning');
        $owner = User::find($this->company->memberships()->value('user_id'));
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/modules/workforce/deactivation-preview')->assertOk()->assertJson(['dependents' => []]); // les modules de B n'interviennent pas
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $other->id])->getJson('/api/modules/workforce/deactivation-preview')->assertForbidden();
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/modules/workforce_payroll/activation-preview')->assertOk()->assertJsonPath('can_activate', true);
    }
}
