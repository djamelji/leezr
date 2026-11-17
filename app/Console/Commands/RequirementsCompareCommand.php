<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use App\Core\Requirements\RequirementParityComparator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-791 (L1) — READ-ONLY report : operational requirement resolution vs parallel resolver.
 * Runs inside a transaction that is always rolled back (defence in depth : nothing can be written).
 *
 *   php artisan requirements:compare --company=1
 */
class RequirementsCompareCommand extends Command
{
    protected $signature = 'requirements:compare {--company=* : company ids (default: all)} {--samples : include classified samples} {--with-registry : ADR-817 — historical projection + the company requirements registry (positions)}';

    protected $description = 'Compare historical and parallel requirement resolution (read-only) — ADR-791';

    public function handle(): int
    {
        $companies = Company::withoutGlobalScopes()->when($this->option('company'), fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')->get();
        $out = [];
        DB::beginTransaction();
        try {
            foreach ($companies as $company) {
                $t = microtime(true);
                DB::enableQueryLog();
                DB::flushQueryLog();
                $report = $this->option('with-registry') ? RequirementParityComparator::compareWithRegistry($company) : RequirementParityComparator::compare($company);
                $report['queries'] = count(DB::getQueryLog());
                $report['ms'] = (int) round((microtime(true) - $t) * 1000);
                DB::disableQueryLog();
                if (! $this->option('samples')) {
                    unset($report['samples']);
                }
                $out[] = $report;
            }
        } finally {
            DB::rollBack();
        }
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
