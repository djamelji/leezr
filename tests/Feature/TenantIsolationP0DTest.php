<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\InteractsWithTenant;
use App\Core\Tenancy\MissingTenantContextException;
use App\Core\Tenancy\QueueTenancyHygiene;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Tests\TestCase;

/**
 * ADR-733 (P0-D) — Isolation multi-tenant HORS HTTP. Démontre le risque latent (scope no-op sans contexte)
 * puis prouve le mécanisme central : runFor isole, nettoie, est ré-entrant ; runGlobal est explicite ;
 * le mode strict est fail-closed ; l'hygiène de queue empêche la contamination entre jobs.
 */
class TenantIsolationP0DTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => []]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyRole::create(['company_id' => $this->a->id, 'key' => 'ra', 'name' => 'Role A', 'is_system' => false, 'is_administrative' => false]);
        CompanyRole::create(['company_id' => $this->b->id, 'key' => 'rb', 'name' => 'Role B', 'is_system' => false, 'is_administrative' => false]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        CompanyContext::$strict = false;
        CompanyContext::clear();
        parent::tearDown();
    }

    // 1. RISQUE DÉMONTRÉ : sans contexte, le scope est no-op → lecture de TOUS les tenants.
    public function test_scope_is_noop_without_context_reads_all_tenants(): void
    {
        CompanyContext::clear();
        $this->assertFalse(CompanyContext::has());
        $this->assertSame(2, CompanyRole::count(), 'sans contexte, la requête tenant-scopée voit les 2 companies');
    }

    // 2. runFor ISOLE la company.
    public function test_run_for_isolates_tenant(): void
    {
        $seenA = CompanyContext::runFor($this->a, fn () => CompanyRole::pluck('company_id')->all());
        $seenB = CompanyContext::runFor($this->b, fn () => CompanyRole::pluck('company_id')->all());
        $this->assertSame([$this->a->id], $seenA);
        $this->assertSame([$this->b->id], $seenB);
    }

    // 3. Le contexte est NETTOYÉ après runFor.
    public function test_context_cleaned_after_run_for(): void
    {
        $this->assertFalse(CompanyContext::has());
        CompanyContext::runFor($this->a, fn () => $this->assertTrue(CompanyContext::has()));
        $this->assertFalse(CompanyContext::has(), 'contexte nettoyé après runFor');
    }

    // 4. Appels IMBRIQUÉS : le contexte précédent est restauré.
    public function test_nested_run_for_restores_previous(): void
    {
        CompanyContext::runFor($this->a, function () {
            $this->assertSame($this->a->id, CompanyContext::id());
            CompanyContext::runFor($this->b, fn () => $this->assertSame($this->b->id, CompanyContext::id()));
            $this->assertSame($this->a->id, CompanyContext::id(), 'contexte A restauré après le bloc B imbriqué');
        });
        $this->assertFalse(CompanyContext::has());
    }

    // 5. runGlobal : pas de contexte, exempt du mode strict, restaure ensuite.
    public function test_run_global_is_contextless_and_restores(): void
    {
        CompanyContext::runFor($this->a, function () {
            CompanyContext::$strict = true;
            $count = CompanyContext::runGlobal(fn () => CompanyRole::count()); // cross-tenant assumé
            $this->assertSame(2, $count);
            $this->assertSame($this->a->id, CompanyContext::id(), 'contexte A restauré après runGlobal');
        });
    }

    // 6. STRICT : une requête tenant sans contexte LÈVE (fail-closed).
    public function test_strict_mode_throws_without_context(): void
    {
        CompanyContext::$strict = true;
        $this->expectException(MissingTenantContextException::class);
        CompanyRole::count();
    }

    // 7. STRICT : une requête SOUS runFor ne lève pas (contexte présent).
    public function test_strict_mode_ok_inside_run_for(): void
    {
        CompanyContext::$strict = true;
        $count = CompanyContext::runFor($this->a, fn () => CompanyRole::count());
        $this->assertSame(1, $count);
    }

    // 8. STRICT : withoutGlobalScopes() contourne le scope → jamais de levée (parcours globaux explicites).
    public function test_strict_mode_ignores_without_global_scopes(): void
    {
        CompanyContext::$strict = true;
        $this->assertSame(2, CompanyRole::withoutGlobalScopes()->count());
    }

    // 9. JOB tenant-aware : s'exécute SOUS le scope de sa company (dispatch réel).
    public function test_tenant_aware_job_isolates(): void
    {
        P0DProbeJob::$seen = null;
        dispatch_sync((new P0DProbeJob)->onTenant($this->a));
        $this->assertSame([$this->a->id], P0DProbeJob::$seen, 'le job ne voit que les données de SA company');
    }

    // 10. JOB tenant-aware sans tenant fixé → fail-closed.
    public function test_tenant_aware_job_without_tenant_throws(): void
    {
        $this->expectException(MissingTenantContextException::class);
        (new P0DProbeJob)->handle();
    }

    // 11. HYGIÈNE DE QUEUE : un job démarre sans le contexte ambiant (comme en worker async) et le restaure.
    public function test_queue_hygiene_clears_and_restores_ambient_context(): void
    {
        CompanyContext::runFor($this->a, function () {
            $this->assertSame($this->a->id, CompanyContext::id());
            QueueTenancyHygiene::before();
            $this->assertFalse(CompanyContext::has(), 'le job démarre sur une ardoise vierge');
            QueueTenancyHygiene::after();
            $this->assertSame($this->a->id, CompanyContext::id(), 'contexte ambiant restauré après le job');
        });
    }

    // 12. HYGIÈNE : pas de contamination entre deux jobs successifs (le 2e ne voit pas le tenant du 1er).
    public function test_no_contamination_between_successive_jobs(): void
    {
        // Job 1 fixe le contexte A puis se termine.
        QueueTenancyHygiene::before();
        CompanyContext::runFor($this->a, fn () => null);
        QueueTenancyHygiene::after();
        // Job 2 démarre : aucun résidu de A.
        QueueTenancyHygiene::before();
        $this->assertFalse(CompanyContext::has(), 'aucun contexte résiduel du job précédent');
        QueueTenancyHygiene::after();
    }
}

/** Job sonde : enregistre les company_id visibles par une requête tenant-scopée sous son contexte. */
class P0DProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithTenant;

    public static ?array $seen = null;

    public function handle(): void
    {
        self::$seen = $this->runInTenant(fn () => CompanyRole::pluck('company_id')->unique()->values()->all());
    }
}
