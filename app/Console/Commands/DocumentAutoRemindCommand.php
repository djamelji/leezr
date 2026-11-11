<?php

namespace App\Console\Commands;

use App\Core\Documents\Services\DocumentLifecycleOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * ADR-397 / ADR-760 (M3) — phase RELANCES déléguée à l'orchestrateur unifié (tiers configurables,
 * dédup déterministe par événement, lock partagé). Conserve son entrée de planification.
 */
class DocumentAutoRemindCommand extends Command implements Isolatable
{
    protected $signature = 'documents:auto-remind {--dry-run : Show what would be reminded without acting}';

    protected $description = 'Send configurable document reminders (delegates to the lifecycle orchestrator).';

    public function handle(DocumentLifecycleOrchestrator $orchestrator): int
    {
        $stats = $orchestrator->run(['remind'], (bool) $this->option('dry-run'));
        $this->info('[dms.lifecycle.remind] '.json_encode($stats));

        return self::SUCCESS;
    }
}
