<?php

namespace App\Console\Commands;

use App\Core\Workforce\DsnDeclaration;
use App\Modules\Workforce\ReadModels\DsnDeclarationReadModel;
use Illuminate\Console\Command;

/**
 * CLI command to validate (inspect validation status of) a DSN declaration.
 *
 * Sprint 6.7 — ADR-525
 */
class DsnValidateCommand extends Command
{
    protected $signature = 'dsn:validate {declaration_id : The DSN declaration ID}';

    protected $description = 'Display validation summary for a DSN declaration';

    public function handle(): int
    {
        $id = (int) $this->argument('declaration_id');
        $summary = DsnDeclarationReadModel::validationSummary($id);

        if (! $summary['found']) {
            $this->error("DSN Declaration #{$id} not found.");

            return self::FAILURE;
        }

        $this->info("DSN Declaration #{$id}");
        $this->line("  Status:       {$summary['status']}");
        $this->line("  Period:       {$summary['period_month']}");
        $this->line("  Payload hash: {$summary['payload_hash']}");
        $this->newLine();

        if ($summary['total_entries'] === 0) {
            $this->info('  No validation issues found.');

            return self::SUCCESS;
        }

        $this->line("  Errors:   {$summary['error_count']}");
        $this->line("  Warnings: {$summary['warning_count']}");
        $this->newLine();

        // By category
        if (! empty($summary['by_category'])) {
            $this->line('  By category:');
            foreach ($summary['by_category'] as $category => $count) {
                $this->line("    {$category}: {$count}");
            }
            $this->newLine();
        }

        // By employee
        if (! empty($summary['by_employee'])) {
            $this->line('  By employee:');
            foreach ($summary['by_employee'] as $employeeId => $count) {
                $this->line("    Employee #{$employeeId}: {$count} issue(s)");
            }
            $this->newLine();
        }

        // Detail entries
        $this->line('  Entries:');
        foreach ($summary['entries'] as $entry) {
            $severity = strtoupper($entry['severity'] ?? 'ERROR');
            $rubrique = $entry['rubrique'] ?? '-';
            $message = $entry['message'] ?? '(no message)';
            $tag = $severity === 'ERROR' ? '<fg=red>[ERROR]</>' : '<fg=yellow>[WARN]</>';
            $this->line("    {$tag} [{$rubrique}] {$message}");
        }

        return $summary['error_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
