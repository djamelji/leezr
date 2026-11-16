<?php

namespace App\Console\Commands;

use App\Core\Jobdomains\Modernization\CompanyJobdomainCompatibility;
use App\Core\Models\Company;
use Illuminate\Console\Command;

/** ADR-797 (L7) — READ-ONLY compatibility report of companies with the versioned model of their JobDomain. */
class JobdomainCompatibilityReportCommand extends Command
{
    protected $signature = 'jobdomains:compatibility-report {company* : company ids} {--json : full JSON}';

    protected $description = 'Read-only compatibility report (presets, requirements, positions, modules, people) — nothing is converted';

    public function handle(): int
    {
        foreach ($this->argument('company') as $id) {
            $r = CompanyJobdomainCompatibility::report(Company::query()->where('id', (int) $id)->firstOrFail());
            $this->line($this->option('json') ? json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : "#{$id} {$r['company']['name']} · ".json_encode(['summary' => $r['summary'], 'people' => array_diff_key($r['people'], ['position_role_pairs' => 1]), 'ambiguous_modules' => $r['modules']['ambiguous']], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
