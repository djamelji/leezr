<?php

namespace App\Console\Commands;

use App\Modules\Workforce\Services\PlanningOutbox;
use Illuminate\Console\Command;

/**
 * Filet de sécurité de l'outbox de notifications de planning (ADR-717).
 *
 * Planifiée chaque minute : envoie les intentions pending qu'un flush post-commit n'aurait pas traitées
 * (crash du process après commit, échec transitoire). Re-tente aussi les lignes `failed` (< 5 tentatives).
 * Idempotent via l'entity_key → aucun doublon même si une ligne a déjà été partiellement envoyée.
 */
class FlushPlanningNotificationsCommand extends Command
{
    protected $signature = 'workforce:flush-planning-notifications {--limit=200}';

    protected $description = 'Flush durable planning notification outbox (reliability net, ADR-717)';

    public function handle(PlanningOutbox $outbox): int
    {
        $outbox->retryFailed();
        $sent = $outbox->flush((int) $this->option('limit'));
        $this->info("Planning notifications flushed: {$sent}");

        return self::SUCCESS;
    }
}
