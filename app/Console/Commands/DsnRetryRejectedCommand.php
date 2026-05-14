<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\DsnDeclaration;
use Illuminate\Console\Command;

/**
 * Reset a rejected DSN declaration back to exported status
 * so it can be re-submitted through the normal flow.
 *
 * This does NOT re-generate or re-submit — it only resets the
 * gateway tracking fields and moves status back to exported.
 * The operator then uses dsn:regenerate + normal submit flow.
 *
 * Sprint 7.5 — ADR-533
 */
class DsnRetryRejectedCommand extends Command
{
    protected $signature = 'dsn:retry-rejected
        {declaration : The DSN declaration ID}
        {--force : Skip confirmation prompt}';

    protected $description = 'Reset a rejected DSN declaration to exported status for re-submission';

    public function handle(): int
    {
        $id = (int) $this->argument('declaration');

        $declaration = DsnDeclaration::find($id);

        if (! $declaration) {
            $this->error("Declaration #{$id} not found.");

            return self::FAILURE;
        }

        if ($declaration->status !== DsnDeclaration::STATUS_REJECTED) {
            $this->error(sprintf(
                'Declaration #%d is not rejected (current status: %s). Only rejected declarations can be retried.',
                $id,
                $declaration->status,
            ));

            return self::FAILURE;
        }

        // Display current state
        $this->line('<fg=cyan>Declaration to retry</>');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $declaration->id],
                ['Company', $declaration->company_id],
                ['Period', $declaration->period_month],
                ['Status', $declaration->status],
                ['Gateway Status', $declaration->gateway_status ?? 'n/a'],
                ['Error Code', $declaration->gateway_error_code ?? 'n/a'],
                ['Error Message', $declaration->gateway_error_message ?? 'n/a'],
                ['Submitted At', $declaration->submitted_at?->format('Y-m-d H:i') ?? 'n/a'],
                ['Attempt Count', $declaration->attempt_count],
            ],
        );

        if (! $this->option('force') && ! $this->confirm('Reset this declaration to exported status?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        // Capture previous state for audit before reset
        $previousGatewayStatus = $declaration->gateway_status;
        $previousErrorCode = $declaration->gateway_error_code;

        // Reset gateway tracking fields via DB bypass (boot guard blocks rejected updates)
        $declaration->resetForRetry();

        // Audit the retry
        app(AuditLogger::class)->logCompany(
            companyId: $declaration->company_id,
            action: 'dsn_declaration.retry_rejected',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => 0, // system/CLI
                'metadata' => [
                    'category' => 'workforce.dsn.ops',
                    'previous_gateway_status' => $previousGatewayStatus,
                    'previous_error_code' => $previousErrorCode,
                ],
            ],
        );

        $this->info(sprintf(
            'Declaration #%d reset to exported. Use dsn:regenerate %d to regenerate, then submit normally.',
            $id,
            $id,
        ));

        return self::SUCCESS;
    }
}
