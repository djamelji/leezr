<?php

namespace App\Console\Commands;

use App\Core\Documents\Services\DocumentLifecycleOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * ADR-389 / ADR-760 (M3) — point d'entrée historique, DÉSORMAIS délégué à l'orchestrateur unifié
 * (renouvellement + relances), idempotent et verrouillé. Aucun double envoi même si auto-renew /
 * auto-remind tournent aussi dans la journée (événements de dédup + lock partagé).
 */
class DocumentCheckExpirationCommand extends Command implements Isolatable
{
    protected $signature = 'documents:check-expiration {--dry-run : Show what would happen without acting}';

    protected $description = 'Unified document lifecycle: renewal requests + configurable reminders (idempotent).';

    public function handle(DocumentLifecycleOrchestrator $orchestrator): int
    {
        $stats = $orchestrator->run(['renew', 'remind'], (bool) $this->option('dry-run'));
        $this->info('[dms.lifecycle] '.json_encode($stats));

        return self::SUCCESS;
    }
}
