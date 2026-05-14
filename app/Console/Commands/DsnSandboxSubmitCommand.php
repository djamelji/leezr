<?php

namespace App\Console\Commands;

use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesHttpClient;
use App\Core\Workforce\Dsn\PreflightResult;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use Illuminate\Console\Command;

/**
 * Submit a DSN declaration via Net-Entreprises in sandbox mode only.
 *
 * Safety guards:
 *   - ONLY works when ne_environment=sandbox
 *   - ONLY works on exported declarations
 *   - Forces net-entreprises gateway regardless of config
 *   - Does NOT require submit_enabled=true
 *
 * Sprint 8.3 — ADR-536
 */
class DsnSandboxSubmitCommand extends Command
{
    protected $signature = 'dsn:sandbox-submit
        {declaration : The DSN declaration ID}
        {--dry-run : Show what would be submitted without making HTTP calls}';

    protected $description = 'Submit a DSN declaration to Net-Entreprises sandbox (test environment only)';

    public function handle(DsnCredentialService $credentialService): int
    {
        $id = (int) $this->argument('declaration');

        // --- Guard: sandbox only ---
        $environment = $credentialService->getEnvironment();

        if ($environment !== 'sandbox') {
            $this->error("BLOCKED: environment is '{$environment}', not 'sandbox'.");
            $this->error('This command only works in sandbox mode. Use the normal submit flow for production.');

            return self::FAILURE;
        }

        // --- Guard: declaration exists ---
        $declaration = DsnDeclaration::find($id);

        if (! $declaration) {
            $this->error("Declaration #{$id} not found.");

            return self::FAILURE;
        }

        // --- Guard: declaration must be exported ---
        if ($declaration->status !== DsnDeclaration::STATUS_EXPORTED) {
            $this->error(sprintf(
                'Declaration #%d is not exported (current status: %s). Only exported declarations can be submitted.',
                $id,
                $declaration->status,
            ));

            return self::FAILURE;
        }

        // --- Guard: credentials present ---
        if (! $credentialService->hasCredentials()) {
            $this->error('No NE credentials configured. Run dsn:check-credentials to diagnose.');

            return self::FAILURE;
        }

        $formatErrors = $credentialService->validateFormats();

        if (! empty($formatErrors)) {
            $this->error('Credential format errors: ' . implode(', ', $formatErrors));

            return self::FAILURE;
        }

        // --- Dry-run: show info without HTTP ---
        $this->line('<fg=cyan>DSN Sandbox Submit</>');
        $this->line(str_repeat('─', 60));
        $this->table(['Field', 'Value'], [
            ['Declaration ID', $declaration->id],
            ['Company', $declaration->company_id],
            ['Period', $declaration->period_month],
            ['Status', $declaration->status],
            ['File Path', $declaration->file_path],
            ['Payload Hash', $declaration->payload_hash],
            ['Gateway Driver', 'net-entreprises (forced)'],
            ['Environment', $environment],
            ['Auth Endpoint', config('workforce.dsn.ne_auth_url')],
            ['Deposit Endpoint', config('workforce.dsn.ne_deposit_url')],
            ['Timeout', config('workforce.dsn.ne_timeout_seconds', 30) . 's'],
            ['Retry Attempts', config('workforce.dsn.ne_retry_attempts', 2)],
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry-run mode — no HTTP call made.');

            return self::SUCCESS;
        }

        // --- Execute sandbox submit ---
        $this->line('');
        $this->line('<fg=yellow>Submitting to NE sandbox...</>');

        try {
            $gateway = new NetEntreprisesDsnGateway(
                client: new NetEntreprisesHttpClient(),
                credentialService: $credentialService,
                disk: 'local',
            );

            // Temporarily force submit_enabled for sandbox
            $previousEnabled = config('workforce.dsn.submit_enabled');
            config(['workforce.dsn.submit_enabled' => true]);

            $useCase = new SubmitDsnDeclarationUseCase($gateway);
            $result = $useCase->execute($declaration, 0); // actorId=0 for CLI

            config(['workforce.dsn.submit_enabled' => $previousEnabled]);
        } catch (\RuntimeException $e) {
            $this->error("Transport error: {$e->getMessage()}");
            $this->line('This is a retryable error. Check network connectivity and try again.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // --- Display result ---
        $statusIcon = match ($result->status) {
            DsnDeclaration::STATUS_SUBMITTED => '<fg=green>✓ SUBMITTED</>',
            DsnDeclaration::STATUS_ACCEPTED => '<fg=green>✓ ACCEPTED</>',
            DsnDeclaration::STATUS_REJECTED => '<fg=red>✗ REJECTED</>',
            default => "<fg=yellow>{$result->status}</>",
        };

        $this->newLine();
        $this->line($statusIcon);
        $this->table(['Field', 'Value'], array_filter([
            ['Status', $result->status],
            ['Reference', $result->submission_reference ?? 'n/a'],
            ['Gateway Status', $result->gateway_status ?? 'n/a'],
            ['Gateway Driver', $result->gateway_driver ?? 'n/a'],
            ['Environment', $result->gateway_environment ?? 'n/a'],
            ['Attempt Count', $result->attempt_count],
            ['Next Poll At', $result->next_poll_at?->format('Y-m-d H:i:s') ?? 'n/a'],
            $result->technical_receipt_path ? ['Receipt Path', $result->technical_receipt_path] : null,
            $result->gateway_error_code ? ['Error Code', $result->gateway_error_code] : null,
            $result->gateway_error_message ? ['Error Message', $result->gateway_error_message] : null,
        ]));

        if ($result->status === DsnDeclaration::STATUS_SUBMITTED) {
            $this->info('Declaration submitted to sandbox. Use dsn:poll-pending to check status.');
        }

        return self::SUCCESS;
    }
}
