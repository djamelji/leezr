<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * ADR-204: Maintenance tool to reconcile stale module activations.
 *
 * Deactivates modules that are no longer entitled (jobdomain defaults changed,
 * compatible_jobdomains changed, etc.). This is a data cleanup tool — the
 * ModuleGate already enforces entitlement dynamically at runtime.
 */
class ReconcileModuleEntitlementsCommand extends Command
{
    protected $signature = 'modules:reconcile-entitlements
                            {--company= : Specific company ID to reconcile}
                            {--dry-run : Preview changes without applying}';

    protected $description = 'Reconcile module activations with current entitlements';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $companyId = $this->option('company');

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be applied.');
        }

        $query = Company::query();

        if ($companyId) {
            $query->where('id', $companyId);
        }

        $companies = $query->get();
        $totalDeactivated = 0;

        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        foreach ($companies as $company) {
            $activeModuleKeys = CompanyModuleActivationReason::where('company_id', $company->id)
                ->whereIn('module_key', $companyModuleKeys)
                ->select('module_key')
                ->distinct()
                ->pluck('module_key');

            $deactivated = [];

            foreach ($activeModuleKeys as $moduleKey) {
                $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

                if (! $manifest || $manifest->type === 'core') {
                    continue;
                }

                $entitlement = EntitlementResolver::check($company, $moduleKey);

                if (! $entitlement['entitled']) {
                    $deactivated[] = $moduleKey;

                    if (! $dryRun) {
                        CompanyModuleActivationReason::where('company_id', $company->id)
                            ->where('module_key', $moduleKey)
                            ->delete();

                        CompanyModule::updateOrCreate(
                            ['company_id' => $company->id, 'module_key' => $moduleKey],
                            ['is_enabled_for_company' => false],
                        );
                    }
                }
            }

            if (count($deactivated) > 0) {
                $label = $dryRun ? 'WOULD deactivate' : 'Deactivated';
                $this->line("  {$company->name} (#{$company->id}): {$label} " . implode(', ', $deactivated));
                $totalDeactivated += count($deactivated);
            }
        }

        $this->info("Done. {$totalDeactivated} module(s) " . ($dryRun ? 'would be' : '') . ' deactivated across ' . $companies->count() . ' company(ies).');

        return Command::SUCCESS;
    }
}
