<?php

namespace App\Console\Commands;

use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E2E test fixture (LOCAL/TESTING only): fully reset an add-on module back to a clean "available"
 * state for a company — remove its addon subscription(s) (incl. end-of-period grace rows),
 * activation reasons, and the company_module enabled flag. Lets the activate/deactivate and
 * dependency specs start from a deterministic baseline without a global migrate:fresh.
 *
 * Does NOT bypass module rules — the specs still drive the real enable/disable afterwards.
 * NOT for production — guarded by environment.
 */
class E2EResetModuleAddonCommand extends Command
{
    protected $signature = 'e2e:reset-module-addon {--company=1} {--module=}';

    protected $description = 'Reset an add-on module to a clean available state (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $companyId = (int) $this->option('company');
        $module = (string) $this->option('module');
        if ($module === '') {
            $this->error('--module is required.');

            return self::FAILURE;
        }

        DB::table('company_addon_subscriptions')->where('company_id', $companyId)->where('module_key', $module)->delete();
        CompanyModuleActivationReason::where('company_id', $companyId)->where('module_key', $module)->delete();
        CompanyModule::where('company_id', $companyId)->where('module_key', $module)->update(['is_enabled_for_company' => false]);

        $this->info("Reset add-on module '{$module}' for company #{$companyId} to available.");

        return self::SUCCESS;
    }
}
