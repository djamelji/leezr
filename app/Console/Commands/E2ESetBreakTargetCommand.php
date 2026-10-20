<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): configure a Company's break TARGET duration (minutes)
 * via the real WorkforceRuleResolver (CompanyPolicy override, mutability + legal-bound enforced).
 * Used to prove that the break countdown is driven by the per-Company resolved config, isolated
 * between companies. NOT for production.
 */
class E2ESetBreakTargetCommand extends Command
{
    protected $signature = 'e2e:set-break-target {--company=1} {--minutes=30}';

    protected $description = 'Set a Company break target (minutes) via the rules engine (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: local/testing only.');

            return self::FAILURE;
        }

        $company = Company::withoutGlobalScopes()->findOrFail((int) $this->option('company'));
        app(WorkforceRuleResolver::class)->setBreakRule($company, 'target_duration_minutes', (int) $this->option('minutes'));

        $this->info("Company #{$company->id} break target set to {$this->option('minutes')} min.");

        return self::SUCCESS;
    }
}
