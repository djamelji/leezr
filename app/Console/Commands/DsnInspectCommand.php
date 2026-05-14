<?php

namespace App\Console\Commands;

use App\Modules\Workforce\ReadModels\DsnAuditReadModel;
use App\Modules\Workforce\ReadModels\DsnDeclarationReadModel;
use Illuminate\Console\Command;

/**
 * CLI command to inspect a DSN declaration: full detail + audit trail.
 *
 * Sprint 6.7 — ADR-525
 */
class DsnInspectCommand extends Command
{
    protected $signature = 'dsn:inspect {declaration_id : The DSN declaration ID} {--audit : Include full audit trail} {--payload : Show payload snapshot}';

    protected $description = 'Inspect a DSN declaration with full detail and optional audit trail';

    public function handle(): int
    {
        $id = (int) $this->argument('declaration_id');
        $showAudit = $this->option('audit');
        $showPayload = $this->option('payload');

        $declaration = DsnDeclarationReadModel::detail($id);

        if (! $declaration) {
            $this->error("DSN Declaration #{$id} not found.");

            return self::FAILURE;
        }

        // ── Header ──
        $this->info("═══ DSN Declaration #{$id} ═══");
        $this->newLine();

        // ── Core fields ──
        $this->line('  <fg=cyan>Core</>');
        $this->line("    Status:           {$declaration->status}");
        $this->line("    Type:             {$declaration->declaration_type}");
        $this->line("    Period:           {$declaration->period_month}");
        $this->line("    Company ID:       {$declaration->company_id}");
        $this->line("    PayrollRun ID:    {$declaration->payroll_run_id}");
        $this->newLine();

        // ── Integrity ──
        $this->line('  <fg=cyan>Integrity</>');
        $this->line("    Payload hash:     {$declaration->payload_hash}");
        $this->line("    File path:        {$declaration->file_path}");
        $this->line("    Can regenerate:   " . ($declaration->canRegenerate() ? 'yes' : 'NO (immutable)'));
        $this->line("    Is terminal:      " . ($declaration->isTerminal() ? 'yes' : 'no'));
        $this->newLine();

        // ── Lifecycle timestamps ──
        $this->line('  <fg=cyan>Lifecycle</>');
        $generatedBy = $declaration->generatedByUser?->name ?? '(system)';
        $this->line("    Generated:        {$declaration->generated_at}  by {$generatedBy}");

        $exportedBy = $declaration->exportedByUser?->name ?? '-';
        $this->line("    Exported:         {$declaration->exported_at}  by {$exportedBy}");

        if ($declaration->submitted_at) {
            $submittedBy = $declaration->submittedByUser?->name ?? '-';
            $this->line("    Submitted:        {$declaration->submitted_at}  by {$submittedBy}");
            $this->line("    Reference:        {$declaration->submission_reference}");
        }
        $this->newLine();

        // ── Validation ──
        $errors = $declaration->validation_errors ?? [];
        $errorCount = count(array_filter($errors, fn ($e) => ($e['severity'] ?? 'error') === 'error'));
        $warnCount = count($errors) - $errorCount;
        $this->line('  <fg=cyan>Validation</>');
        $this->line("    Total entries:    " . count($errors));
        $this->line("    Errors:           {$errorCount}");
        $this->line("    Warnings:         {$warnCount}");

        if (count($errors) > 0) {
            foreach ($errors as $entry) {
                $sev = strtoupper($entry['severity'] ?? 'ERROR');
                $tag = $sev === 'ERROR' ? '<fg=red>[ERROR]</>' : '<fg=yellow>[WARN]</>';
                $rubrique = $entry['rubrique'] ?? '-';
                $msg = $entry['message'] ?? '';
                $this->line("      {$tag} [{$rubrique}] {$msg}");
            }
        }
        $this->newLine();

        // ── PayrollRun info ──
        if ($declaration->payrollRun) {
            $run = $declaration->payrollRun;
            $this->line('  <fg=cyan>PayrollRun</>');
            $this->line("    ID:               {$run->id}");
            $this->line("    Period:           {$run->period_start} → {$run->period_end}");
            $this->line("    Status:           {$run->status}");
            $this->line("    Employees:        {$run->employee_count}");
            $this->newLine();
        }

        // ── Audit trail ──
        if ($showAudit) {
            $history = DsnAuditReadModel::historyForDeclaration($id);
            $this->line('  <fg=cyan>Audit Trail (' . $history->count() . ' entries)</>');

            if ($history->isEmpty()) {
                $this->line('    (no audit entries)');
            } else {
                foreach ($history as $entry) {
                    $this->line("    [{$entry['created_at']}] {$entry['action']} (actor: {$entry['actor_id']})");
                }
            }
            $this->newLine();

            // Hash timeline
            $hashTimeline = DsnAuditReadModel::payloadHashTimeline($id);
            if ($hashTimeline->isNotEmpty()) {
                $this->line('  <fg=cyan>Hash Timeline</>');
                foreach ($hashTimeline as $point) {
                    $hash = $point['payload_hash'] ? substr($point['payload_hash'], 0, 16) . '...' : '-';
                    $this->line("    [{$point['created_at']}] {$point['action']} → {$hash}");
                }
                $this->newLine();
            }
        }

        // ── Payload snapshot ──
        if ($showPayload && $declaration->payload_snapshot) {
            $this->line('  <fg=cyan>Payload Snapshot</>');
            $this->line(json_encode($declaration->payload_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
