<?php

namespace App\Core\Tenancy;

use App\Core\Models\Company;
use Closure;

/**
 * ADR-733 (P0-D) — À utiliser par les Jobs/Listeners asynchrones TENANT (company-scoped). Transporte le
 * `company_id` dans le payload sérialisé (pas le modèle), le valide et établit le contexte via
 * `CompanyContext::runFor` le temps de l'exécution — puis le nettoie. Garantit que, même en worker
 * persistant, le job travaille SOUS le scope de SA company (plus de dépendance au no-op ni de
 * contamination entre jobs).
 *
 * Usage :
 *   class MyJob implements ShouldQueue {
 *     use InteractsWithTenant;
 *     public function __construct(Company|int $company) { $this->onTenant($company); }
 *     public function handle(): void { $this->runInTenant(fn () => $this->work()); }
 *   }
 * Dans runInTenant(), toutes les requêtes tenant-scopées sont filtrées par la company du job.
 */
trait InteractsWithTenant
{
    public ?int $tenantCompanyId = null;

    public function onTenant(Company|int $company): static
    {
        $this->tenantCompanyId = $company instanceof Company ? $company->id : (int) $company;

        return $this;
    }

    /** Exécute $fn sous le contexte de la company du job. Lève si le tenant n'a pas été fixé (fail-closed). */
    protected function runInTenant(Closure $fn): mixed
    {
        if (! $this->tenantCompanyId) {
            throw new MissingTenantContextException(static::class);
        }

        return CompanyContext::runFor($this->tenantCompanyId, $fn);
    }
}
