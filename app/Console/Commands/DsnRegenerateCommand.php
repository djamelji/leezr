<?php

namespace App\Console\Commands;

use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Modules\Workforce\UseCases\ExportPayrollDsnUseCase;
use Illuminate\Console\Command;

/**
 * CLI command to regenerate a DSN declaration.
 *
 * Guards: only allowed if canRegenerate() (draft/validated/exported/rejected).
 * Blocked if submitted or accepted.
 *
 * Sprint 6.7 — ADR-525
 */
class DsnRegenerateCommand extends Command
{
    protected $signature = 'dsn:regenerate {declaration_id : The DSN declaration ID} {--dry-run : Preview without executing}';

    protected $description = 'Regenerate a DSN declaration from its payroll run';

    public function handle(): int
    {
        $id = (int) $this->argument('declaration_id');
        $dryRun = $this->option('dry-run');

        $declaration = DsnDeclaration::withoutGlobalScopes()->find($id);

        if (! $declaration) {
            $this->error("DSN Declaration #{$id} not found.");

            return self::FAILURE;
        }

        $this->info("DSN Declaration #{$id}");
        $this->line("  Status:       {$declaration->status}");
        $this->line("  Period:       {$declaration->period_month}");
        $this->line("  PayrollRun:   #{$declaration->payroll_run_id}");
        $this->line("  Payload hash: {$declaration->payload_hash}");
        $this->newLine();

        if (! $declaration->canRegenerate()) {
            $this->error("Cannot regenerate: declaration is '{$declaration->status}' (immutable after submission).");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('[DRY-RUN] Would regenerate this declaration. No changes made.');

            return self::SUCCESS;
        }

        $run = PayrollRun::withoutGlobalScopes()->find($declaration->payroll_run_id);

        if (! $run) {
            $this->error("PayrollRun #{$declaration->payroll_run_id} not found.");

            return self::FAILURE;
        }

        try {
            $useCase = app(ExportPayrollDsnUseCase::class);
            $newDeclaration = $useCase->execute($run, auth()->id() ?? 0);

            $this->info('Regeneration successful.');
            $this->line("  New ID:       #{$newDeclaration->id}");
            $this->line("  New status:   {$newDeclaration->status}");
            $this->line("  New hash:     {$newDeclaration->payload_hash}");
            $this->line("  File:         {$newDeclaration->file_path}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Regeneration failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
