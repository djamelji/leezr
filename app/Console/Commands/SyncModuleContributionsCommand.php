<?php

namespace App\Console\Commands;

use App\Core\Modules\Contributions\ModuleContributionCatalog;
use Illuminate\Console\Command;

/** ADR-794 (L4) — Platform : synchronise module contributions (catalogue + proposal templates). Idempotent. */
class SyncModuleContributionsCommand extends Command
{
    protected $signature = 'modules:sync-contributions {--dry-run : compute the report inside a rolled-back transaction}';

    protected $description = 'Sync module contributions (definitions and requirement proposals) — never imposes anything on a company';

    public function handle(): int
    {
        $report = ModuleContributionCatalog::sync(null, (bool) $this->option('dry-run'));
        $rows = [];
        foreach ($report as $module => $items) {
            foreach ($items as $i) {
                $rows[] = [$module, $i['kind'], $i['ref'], $i['outcome'], $i['detail'] ?? ''];
            }
        }
        $this->table(['module', 'kind', 'ref', 'outcome', 'detail'], $rows);
        $invalid = collect($rows)->where(3, 'invalid')->count();

        return $invalid ? self::FAILURE : self::SUCCESS;
    }
}
