<?php

namespace App\Console\Commands;

use App\Core\Modules\CompanyModule;
use App\Modules\Workforce\UseCases\AccrueLeaveUseCase;
use Illuminate\Console\Command;

/**
 * W-HR-UX-5 (P3) — acquisition mensuelle des congés, pour toutes les companies dont le module
 * `workforce_leave` est actif. Rejouable sans double crédit : l'idempotency_key mensuel
 * `accrual:{emp}:{type}:{YYYY-MM}` protège chaque (salarié, type, mois). Planifié le 1er du mois.
 *
 * Ne crédite QUE les types dont une règle d'accrual mensuel est configurée (accrual_mode=monthly,
 * entitlement>0) et uniquement les salariés actifs avec contrat actif — aucun droit inventé.
 */
class AccrueLeaveCommand extends Command
{
    protected $signature = 'leave:accrue {--period= : YYYY-MM (défaut = mois courant)} {--company= : limiter à une company}';

    protected $description = 'Acquisition mensuelle des congés pour les companies avec module workforce_leave (idempotent)';

    public function handle(AccrueLeaveUseCase $useCase): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');

        $query = CompanyModule::query()
            ->where('module_key', 'workforce_leave')
            ->where('is_enabled_for_company', true);

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        $companyIds = $query->pluck('company_id')->unique()->values();

        $total = 0;
        foreach ($companyIds as $cid) {
            try {
                $processed = $useCase->execute((int) $cid, $period);
                $total += $processed;
                $this->line("company #{$cid}: {$processed} salarié(s) crédité(s) pour {$period}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("company #{$cid}: {$e->getMessage()}");
            }
        }

        $this->info("Accrual {$period} terminé : {$companyIds->count()} company(ies), {$total} salarié(s) traité(s).");

        return self::SUCCESS;
    }
}
