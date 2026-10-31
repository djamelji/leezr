<?php

namespace App\Console\Commands;

use App\Core\Workforce\LeaveRequest;
use App\Modules\Workforce\UseCases\ConsumeLeaveUseCase;
use Illuminate\Console\Command;

/**
 * W-HR-UX-5 (P3) — consommation définitive des congés APPROUVÉS dont la date de début est atteinte.
 *
 * Ferme le trou du cycle de vie : à l'approbation on RÉSERVE (debit reservation) ; la transition
 * `approved → consumed` n'était jamais déclenchée. Ce job quotidien transforme la réservation en
 * consommation (release + consumption) pour tout congé `approved` dont `date_from <= today`.
 *
 * Idempotent : `ConsumeLeaveUseCase` est gardé par status=approved + idempotency_key `consume:{id}`
 * (unique en DB) → aucune double consommation même sous ré-exécution ou concurrence. Append-only,
 * aucun recalcul destructif des soldes historiques.
 */
class ConsumeDueLeaveCommand extends Command
{
    protected $signature = 'leave:consume-due {--date= : YYYY-MM-DD (défaut = aujourd\'hui)} {--company= : limiter à une company}';

    protected $description = 'Consomme les congés approuvés dont la date de début est atteinte (idempotent)';

    public function handle(ConsumeLeaveUseCase $useCase): int
    {
        $date = $this->option('date') ?: now()->toDateString();

        $query = LeaveRequest::withoutGlobalScopes()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('date_from', '<=', $date);

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        $requests = $query->orderBy('date_from')->get();

        $consumed = 0;
        foreach ($requests as $request) {
            try {
                $useCase->execute($request);
                $consumed++;
            } catch (\Throwable $e) {
                report($e);
                $this->error("leave #{$request->id}: {$e->getMessage()}");
            }
        }

        $this->info("Consommation au {$date} : {$consumed}/{$requests->count()} congé(s) approuvé(s) consommé(s).");

        return self::SUCCESS;
    }
}
