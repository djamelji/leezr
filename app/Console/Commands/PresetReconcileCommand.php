<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Jobdomains\PresetReconciler;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * ADR-375: Artisan command for preset reconciliation.
 *
 * Usage:
 *   php artisan permissions:reconcile                 # dry-run all companies
 *   php artisan permissions:reconcile --apply         # apply fixes
 *   php artisan permissions:reconcile --company=42    # single company
 *   php artisan permissions:reconcile --jobdomain=logistique  # by jobdomain
 *   php artisan permissions:reconcile --json          # JSON output
 */
class PresetReconcileCommand extends Command
{
    protected $signature = 'permissions:reconcile
        {--company= : Reconcile a specific company by ID}
        {--jobdomain= : Reconcile all companies for a jobdomain key}
        {--apply : Apply fixes (default: dry-run)}
        {--json : Output as JSON}';

    protected $description = 'Detect and fix drift between company roles/permissions and jobdomain presets';

    public function handle(): int
    {
        // Ensure registries are fresh
        ModuleRegistry::clearCache();
        JobdomainRegistry::sync();
        CompanyPermissionCatalog::sync();

        $apply = $this->option('apply');
        $json = $this->option('json');

        if (! $apply && ! $json) {
            $this->info('DRY-RUN mode — no changes will be made. Use --apply to fix drift.');
        }

        if ($companyId = $this->option('company')) {
            $company = Company::findOrFail($companyId);
            $report = PresetReconciler::reconcile($company, $apply);
        } elseif ($jobdomain = $this->option('jobdomain')) {
            $report = PresetReconciler::reconcileByJobdomain($jobdomain, $apply);
        } else {
            $report = PresetReconciler::reconcileAll($apply);
        }

        if ($json) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Summary
        $summary = $report->summary();
        $this->newLine();
        $this->info("=== Reconciliation Report ===");
        $this->info("Up to date: {$summary['up_to_date']}");
        $this->warn("Drifted:    {$summary['drifted']}" . ($apply ? ' (APPLIED)' : ' (dry-run)'));
        $this->info("Skipped:    {$summary['skipped']}");

        if ($summary['warnings'] > 0) {
            $this->warn("Warnings:   {$summary['warnings']}");
        }

        // Details
        if (! empty($report->drifted)) {
            $this->newLine();
            $this->info('--- Drifted Roles ---');

            foreach ($report->drifted as $drift) {
                $this->line("  Company #{$drift['company_id']} / role '{$drift['role_key']}' (id={$drift['role_id']})");

                if (! empty($drift['missing'])) {
                    $this->warn('    Missing: ' . implode(', ', $drift['missing']));
                }

                if (! empty($drift['extra'])) {
                    $this->warn('    Extra:   ' . implode(', ', $drift['extra']));
                }

                if ($drift['applied']) {
                    $this->info('    → FIXED');
                }
            }
        }

        if (! empty($report->skipped)) {
            $this->newLine();
            $this->info('--- Skipped Roles ---');

            foreach ($report->skipped as $skip) {
                $this->line("  Company #{$skip['company_id']} / role '{$skip['role_key']}' — {$skip['reason']}");
            }
        }

        if (! empty($report->warnings)) {
            $this->newLine();
            $this->warn('--- Warnings ---');

            foreach ($report->warnings as $warning) {
                $this->warn("  {$warning}");
            }
        }

        return $report->hasDrift() && ! $apply ? self::FAILURE : self::SUCCESS;
    }
}
