<?php

namespace App\Console\Commands;

use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Core\System\SystemSync;
use Database\Seeders\SystemSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * ADR-832 — synchronisation of the system definitions with a readable report.
 *
 *   php artisan system:sync --dry-run                 what a deployment would change (rolled back, nothing kept)
 *   php artisan system:sync --force                   same as the deployment step (db:seed --class=SystemSeeder)
 *   php artisan system:sync --adopt=plans:pro:price_monthly --force
 *                                                     deliberately aligns ONE administered value on the code
 *   --json=path                                       full report (values included) for the release evidence
 *
 * Administered values changed in Platform are never overwritten without `--adopt`.
 */
class SystemSyncCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'system:sync {--dry-run : Run inside a transaction rolled back at the end}
        {--adopt=* : subject:key:field (or * wildcards) whose code value is deliberately applied}
        {--json= : Write the full report to this path}
        {--force : Run in production without confirmation}';

    protected $description = 'Install/align the system definitions without overwriting Platform-administered configuration (ADR-832)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        SystemSync::begin((array) $this->option('adopt'));
        $seeder = app(SystemSeeder::class)->setContainer(app())->setCommand($this);

        if ($dry) {
            DB::beginTransaction();
        }
        try {
            $seeder->__invoke();
        } finally {
            if ($dry) {
                DB::rollBack();
                PlanRegistry::clearCache();
                ModuleRegistry::clearCache();
                MarketRegistry::clearCache();
                if (method_exists(JobdomainRegistry::class, 'clearCache')) {
                    JobdomainRegistry::clearCache();
                }
            }
        }

        SystemSeeder::printReport($this);
        $conflicts = count(SystemSync::conflicts());
        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'dry_run' => $dry,
                'adopt' => (array) $this->option('adopt'),
                'summary' => SystemSync::summary(),
                'entries' => SystemSync::report(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line("Report: {$path}");
        }
        SystemSync::end();

        $this->line(($dry ? 'DRY RUN — nothing kept. ' : '').($conflicts === 0 ? 'No conflict.' : "{$conflicts} conflict(s): administered values preserved."));

        return self::SUCCESS;
    }
}
