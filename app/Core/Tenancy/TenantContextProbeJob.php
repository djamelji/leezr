<?php

namespace App\Core\Tenancy;

use App\Company\RBAC\CompanyRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-733.2 (P0-D.2) — Sonde DIAGNOSTIQUE d'isolation tenant en WORKER RÉEL (queue database/redis).
 *
 * Utilisée exclusivement par la commande `tenancy:worker-proof` pour prouver, sur un worker persistant
 * (pas le driver sync), que : (1) le job démarre sans contexte résiduel du job précédent (hygiène),
 * (2) sous son contexte tenant, une requête scopée ne voit QUE sa company. Enregistre le résultat en
 * cache (clé fournie) pour relecture par la commande. Queue dédiée `p0d2-proof` → n'interfère pas avec
 * la file applicative.
 */
class TenantContextProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithTenant;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(int $companyId, public readonly string $resultKey)
    {
        $this->onTenant($companyId);
        $this->onQueue('p0d2-proof');
    }

    public function handle(): void
    {
        // Avant toute installation de contexte : le worker doit nous livrer une ardoise vierge (hygiène).
        $contextAtStart = CompanyContext::has();

        // Sous le contexte de SA company, une requête tenant-scopée ne voit que ses propres lignes.
        $seen = $this->runInTenant(fn () => CompanyRole::query()->pluck('company_id')->unique()->sort()->values()->all());

        Cache::put($this->resultKey, [
            'tenant' => $this->tenantCompanyId,
            'seen' => $seen,
            'context_at_start' => $contextAtStart,
            'context_after' => CompanyContext::has(),
        ], 600);
    }
}
