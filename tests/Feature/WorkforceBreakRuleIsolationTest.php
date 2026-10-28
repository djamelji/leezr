<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Réserve B (depuis W-D) — NON-RÉGRESSION déterministe de l'isolation des règles de pause.
 *
 * Diagnostic : l'échec e2e `workforce-two-company-isolation` ne venait PAS du moteur, de l'endpoint ni
 * de l'assertion, mais d'un PRÉREQUIS DE FIXTURE non exécuté (`php artisan e2e:setup-two-companies`).
 * Avec la fixture l'e2e passe (2/2). Ce test verrouille le comportement produit SANS dépendre de
 * l'ordre d'exécution : deux Companies résolvent chacune leur propre cible de pause, isolées.
 */
class WorkforceBreakRuleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);
        Jobdomain::create(['key' => 'beta-services', 'label' => 'Beta', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
    }

    private function company(string $jobdomain): Company
    {
        return Company::create(['name' => 'C '.uniqid(), 'slug' => 'c-'.uniqid(), 'plan_key' => 'pro', 'market_key' => 'FR', 'jobdomain_key' => $jobdomain, 'status' => 'active']);
    }

    public function test_two_companies_resolve_distinct_isolated_break_targets(): void
    {
        $resolver = app(WorkforceRuleResolver::class);
        $a = $this->company('logistique');
        $b = $this->company('beta-services');

        $resolver->setBreakRule($a, 'target_duration_minutes', 30);
        $resolver->setBreakRule($b, 'target_duration_minutes', 45);

        // Chaque Company résout SA propre cible, côté serveur, sans fuite entre tenants.
        $this->assertSame(30, $resolver->breakTargetMinutes($a), 'Company A = 30');
        $this->assertSame(45, $resolver->breakTargetMinutes($b), 'Company B = 45');

        // La provenance est exposée dans la résolution (valeur effective par Company).
        $this->assertSame(30, $resolver->resolveBreakRules($a)['target_duration_minutes']['value']);
        $this->assertSame(45, $resolver->resolveBreakRules($b)['target_duration_minutes']['value']);
    }

    public function test_company_without_override_is_independent_of_another_tenant(): void
    {
        $resolver = app(WorkforceRuleResolver::class);
        $a = $this->company('logistique');
        $b = $this->company('logistique'); // même jobdomain : seule la surcharge Company distingue

        $before = $resolver->breakTargetMinutes($b); // B sans surcharge → valeur par défaut
        $resolver->setBreakRule($a, 'target_duration_minutes', 30);

        $this->assertSame(30, $resolver->breakTargetMinutes($a));
        $this->assertSame($before, $resolver->breakTargetMinutes($b), 'B (sans surcharge) inchangée par A → aucune fuite');
    }
}
