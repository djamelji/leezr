<?php

namespace App\Console\Commands;

use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Idempotent cleanup: removes non-company-scope module activations
 * from the company_modules table (e.g. platform.*, payments.*).
 *
 * Safe to run multiple times — only deletes rows whose module_key
 * is NOT in ModuleRegistry::forScope('company').
 */
class CompanyCleanupModulesCommand extends Command
{
    protected $signature = 'company:cleanup-modules
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Remove non-company-scope modules from company_modules table';

    public function handle(): int
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        $orphaned = CompanyModule::whereNotIn('module_key', $companyModuleKeys)->get();

        if ($orphaned->isEmpty()) {
            $this->info('No orphaned module activations found. Nothing to clean.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Company ID', 'Module Key', 'Enabled'],
            $orphaned->map(fn ($row) => [
                $row->id,
                $row->company_id,
                $row->module_key,
                $row->is_enabled_for_company ? 'Yes' : 'No',
            ])->toArray()
        );

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$orphaned->count()} row(s) would be deleted.");

            return self::SUCCESS;
        }

        $deleted = CompanyModule::whereNotIn('module_key', $companyModuleKeys)->delete();

        $this->info("Cleaned up {$deleted} orphaned module activation(s).");

        return self::SUCCESS;
    }
}
