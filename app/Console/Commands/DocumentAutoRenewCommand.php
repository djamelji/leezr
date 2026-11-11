<?php

namespace App\Console\Commands;

use App\Core\Documents\Services\DocumentLifecycleOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * ADR-397 / ADR-760 (M3) — phase RENOUVELLEMENT déléguée à l'orchestrateur unifié (idempotent,
 * verrouillé). Conserve son entrée de planification ; la logique vit dans l'orchestrateur.
 */
class DocumentAutoRenewCommand extends Command implements Isolatable
{
    protected $signature = 'documents:auto-renew {--dry-run : Show what would be renewed without acting}';

    protected $description = 'Open renewal requests for expiring documents (delegates to the lifecycle orchestrator).';

    public function handle(DocumentLifecycleOrchestrator $orchestrator): int
    {
        $stats = $orchestrator->run(['renew'], (bool) $this->option('dry-run'));
        $this->info('[dms.lifecycle.renew] '.json_encode($stats));

        return self::SUCCESS;
    }
}
