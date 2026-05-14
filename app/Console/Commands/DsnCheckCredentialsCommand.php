<?php

namespace App\Console\Commands;

use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use Illuminate\Console\Command;

/**
 * Smoke test for Net-Entreprises DSN credentials.
 *
 * Validates that credentials exist and have correct formats
 * without contacting the remote server.
 *
 * Sprint 7.1 — ADR-529
 */
class DsnCheckCredentialsCommand extends Command
{
    protected $signature = 'dsn:check-credentials';

    protected $description = 'Validate Net-Entreprises DSN credentials configuration';

    public function handle(DsnCredentialService $service): int
    {
        $this->newLine();
        $this->line('<fg=cyan>DSN Credentials Check</>');
        $this->line(str_repeat('─', 44));

        // 1. Check presence
        if (! $service->hasCredentials()) {
            $this->error('DSN credentials NOT configured in PlatformSetting.');
            $this->newLine();
            $this->line('Configure via platform admin → Settings → DSN tab');
            $this->line('Required: ne_siret, ne_nom, ne_prenom, ne_password');

            return self::FAILURE;
        }

        $credentials = $service->getCredentials();

        // 2. Display status (mask sensitive fields)
        $this->table(
            ['Field', 'Status', 'Value'],
            [
                ['ne_siret', $this->statusBadge(! empty($credentials['ne_siret'])), $this->maskSiret($credentials['ne_siret'] ?? '')],
                ['ne_nom', $this->statusBadge(! empty($credentials['ne_nom'])), $credentials['ne_nom'] ?? '(missing)'],
                ['ne_prenom', $this->statusBadge(! empty($credentials['ne_prenom'])), $credentials['ne_prenom'] ?? '(missing)'],
                ['ne_password', $this->statusBadge(! empty($credentials['ne_password'])), ! empty($credentials['ne_password']) ? '••••••••' : '(missing)'],
                ['ne_environment', $this->statusBadge(true), $service->getEnvironment()],
            ],
        );

        // 3. Format validation
        $errors = $service->validateFormats();

        if (empty($errors)) {
            $this->newLine();
            $this->info('All credentials valid.');

            // 4. Show gateway config
            $this->newLine();
            $this->line('<fg=cyan>Gateway Configuration</>');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['gateway_driver', config('workforce.dsn.gateway')],
                    ['submit_enabled', config('workforce.dsn.submit_enabled') ? 'true' : 'false'],
                    ['ne_environment', config('workforce.dsn.ne_environment')],
                    ['ne_auth_url', config('workforce.dsn.ne_auth_url')],
                    ['ne_deposit_url', config('workforce.dsn.ne_deposit_url')],
                    ['ne_status_url', config('workforce.dsn.ne_status_url')],
                ],
            );

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Credential validation errors:');
        foreach ($errors as $field => $message) {
            $this->line("  <fg=red>✗</> {$field}: {$message}");
        }

        return self::FAILURE;
    }

    private function statusBadge(bool $ok): string
    {
        return $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
    }

    private function maskSiret(string $siret): string
    {
        if (strlen($siret) < 14) {
            return $siret ?: '(missing)';
        }

        return substr($siret, 0, 3) . '•••••••••' . substr($siret, -2);
    }
}
