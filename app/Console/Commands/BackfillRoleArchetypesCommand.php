<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use Illuminate\Console\Command;

/**
 * ADR-170 Phase 2: Backfill archetype + required_tags on existing system roles.
 *
 * For each company with a jobdomain_key, looks up the registry definition
 * and maps role key → archetype → default_tags.
 *
 * Idempotent: safe to run multiple times.
 */
class BackfillRoleArchetypesCommand extends Command
{
    protected $signature = 'role:backfill-archetypes {--dry-run : Show what would change without persisting}';

    protected $description = 'Backfill archetype and required_tags on existing system roles from jobdomain registry';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        $companies = Company::whereNotNull('jobdomain_key')->get();

        foreach ($companies as $company) {
            $registryDef = JobdomainRegistry::get($company->jobdomain_key);
            if (!$registryDef) {
                $this->warn("No registry definition for jobdomain '{$company->jobdomain_key}' (company #{$company->id})");

                continue;
            }

            $archetypes = $registryDef['archetypes'] ?? [];
            $defaultRoles = $registryDef['default_roles'] ?? [];

            $roles = CompanyRole::where('company_id', $company->id)
                ->where('is_system', true)
                ->get();

            foreach ($roles as $role) {
                $roleDef = $defaultRoles[$role->key] ?? null;
                if (!$roleDef) {
                    $skipped++;

                    continue;
                }

                $archetype = $roleDef['archetype'] ?? null;
                if (!$archetype) {
                    $skipped++;

                    continue;
                }

                $defaultTags = $archetypes[$archetype]['default_tags'] ?? [];

                if ($role->archetype === $archetype && $role->required_tags === $defaultTags) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->info("[DRY] Company #{$company->id} role '{$role->key}' → archetype={$archetype}, tags=" . json_encode($defaultTags));
                } else {
                    $role->update([
                        'archetype' => $archetype,
                        'required_tags' => $defaultTags,
                    ]);
                }

                $updated++;
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
