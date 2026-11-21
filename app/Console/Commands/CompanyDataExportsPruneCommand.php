<?php

namespace App\Console\Commands;

use App\Modules\Core\DataExport\CompanyDataExportService;
use Illuminate\Console\Command;

/** ADR-845 (Leezr 4.2 M8) — deletes expired export ARCHIVES (rows kept as trace). Never touches company data. */
class CompanyDataExportsPruneCommand extends Command
{
    protected $signature = 'company-exports:prune';

    protected $description = 'Delete expired company data export archives (the export rows stay as trace)';

    public function handle(CompanyDataExportService $service): int
    {
        $this->info('Expired archives removed: '.$service->pruneExpired());

        return self::SUCCESS;
    }
}
