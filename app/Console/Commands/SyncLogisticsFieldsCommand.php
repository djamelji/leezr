<?php

namespace App\Console\Commands;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use Illuminate\Console\Command;

/**
 * Sync logistics field activations for existing companies.
 *
 * Idempotent: only creates missing FieldActivation records.
 * Never touches existing field_config on roles.
 */
class SyncLogisticsFieldsCommand extends Command
{
    protected $signature = 'jobdomain:sync-logistics-fields
                            {--dry-run : Show what would be activated without writing}';

    protected $description = 'Activate missing logistics field definitions for existing companies with the logistique jobdomain';

    public function handle(): int
    {
        $definition = JobdomainRegistry::get('logistique');

        if (!$definition) {
            $this->error('Logistique jobdomain not found in registry.');

            return self::FAILURE;
        }

        $defaultFields = $definition['default_fields'] ?? [];
        $fieldCodes = collect($defaultFields)->pluck('code')->toArray();
        $fieldConfigs = collect($defaultFields)->keyBy('code');

        // Load all system definitions we need
        $definitions = FieldDefinition::whereNull('company_id')
            ->whereIn('code', $fieldCodes)
            ->get()
            ->keyBy('code');

        if ($definitions->isEmpty()) {
            $this->warn('No matching FieldDefinitions found. Run FieldDefinitionCatalog::sync() first.');

            return self::FAILURE;
        }

        // Find all companies with logistique jobdomain
        $companies = Company::whereHas('jobdomains', fn ($q) => $q->where('key', 'logistique'))->get();

        if ($companies->isEmpty()) {
            $this->info('No companies with logistique jobdomain found.');

            return self::SUCCESS;
        }

        $this->info("Found {$companies->count()} company(ies) with logistique jobdomain.");
        $totalCreated = 0;

        foreach ($companies as $company) {
            $existingActivations = FieldActivation::where('company_id', $company->id)
                ->whereIn('field_definition_id', $definitions->pluck('id'))
                ->pluck('field_definition_id')
                ->toArray();

            $created = 0;

            foreach ($definitions as $code => $def) {
                if (in_array($def->id, $existingActivations)) {
                    continue;
                }

                $config = $fieldConfigs->get($code);

                if ($this->option('dry-run')) {
                    $this->line("  [DRY-RUN] Would activate '{$code}' for company #{$company->id} ({$company->name})");
                    $created++;

                    continue;
                }

                FieldActivation::create([
                    'company_id' => $company->id,
                    'field_definition_id' => $def->id,
                    'enabled' => true,
                    'required_override' => $config['required'] ?? false,
                    'order' => $config['order'] ?? $def->default_order ?? 0,
                ]);

                $created++;
            }

            if ($created > 0) {
                $prefix = $this->option('dry-run') ? '[DRY-RUN] ' : '';
                $this->info("{$prefix}Company #{$company->id} ({$company->name}): {$created} field(s) activated.");
            }

            $totalCreated += $created;
        }

        $prefix = $this->option('dry-run') ? '[DRY-RUN] ' : '';
        $this->info("{$prefix}Total: {$totalCreated} field activation(s) across {$companies->count()} company(ies).");

        return self::SUCCESS;
    }
}
