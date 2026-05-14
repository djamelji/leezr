<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;

/**
 * DSN Gateway health check — evaluates operational readiness.
 *
 * Returns a structured report with status: green (all good),
 * yellow (warnings), red (not operational).
 *
 * Sprint 7.5 — ADR-533, Sprint 8.3 — ADR-536
 */
class DsnGatewayHealthCheck
{
    public function __construct(
        private DsnCredentialService $credentialService,
    ) {}

    /**
     * Run full health check.
     *
     * @return array{status: string, checks: array, checked_at: string, environment: string}
     */
    public function check(): array
    {
        $checks = [
            $this->checkEnvironment(),
            $this->checkEndpoints(),
            $this->checkSubmitEnabled(),
            $this->checkCredentials(),
            $this->checkGatewayDriver(),
            $this->checkLastSubmit(),
            $this->checkRecentErrors(),
            $this->checkPendingPolls(),
        ];

        $status = $this->aggregateStatus($checks);
        $environment = $this->credentialService->hasCredentials()
            ? $this->credentialService->getEnvironment()
            : config('workforce.dsn.ne_environment', 'sandbox');

        return [
            'status' => $status,
            'checks' => $checks,
            'environment' => $environment,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function checkEnvironment(): array
    {
        $environment = $this->credentialService->hasCredentials()
            ? $this->credentialService->getEnvironment()
            : config('workforce.dsn.ne_environment', 'sandbox');

        $isSandbox = $environment === 'sandbox';

        return [
            'key' => 'environment',
            'label' => 'NE Environment',
            'status' => $isSandbox ? 'yellow' : 'green',
            'detail' => $isSandbox
                ? "sandbox — test mode, no real declarations transmitted"
                : "production — LIVE mode, declarations transmitted to URSSAF/CPAM",
        ];
    }

    private function checkEndpoints(): array
    {
        $authUrl = config('workforce.dsn.ne_auth_url', '');
        $depositUrl = config('workforce.dsn.ne_deposit_url', '');
        $statusUrl = config('workforce.dsn.ne_status_url', '');

        $environment = $this->credentialService->hasCredentials()
            ? $this->credentialService->getEnvironment()
            : config('workforce.dsn.ne_environment', 'sandbox');

        $isSandbox = $environment === 'sandbox';
        $hasTestUrls = str_contains($authUrl, 'test') || str_contains($depositUrl, 'test');
        $hasProdUrls = ! $hasTestUrls && ! empty($authUrl);

        // Mismatch detection: sandbox env with prod URLs or production env with test URLs
        if ($isSandbox && $hasProdUrls) {
            return [
                'key' => 'endpoints',
                'label' => 'API Endpoints',
                'status' => 'red',
                'detail' => 'MISMATCH: environment=sandbox but URLs point to production!',
            ];
        }

        if (! $isSandbox && $hasTestUrls) {
            return [
                'key' => 'endpoints',
                'label' => 'API Endpoints',
                'status' => 'red',
                'detail' => 'MISMATCH: environment=production but URLs point to test!',
            ];
        }

        $urlSummary = $isSandbox ? 'test-services.net-entreprises.fr' : 'services.net-entreprises.fr';

        return [
            'key' => 'endpoints',
            'label' => 'API Endpoints',
            'status' => 'green',
            'detail' => "Consistent ({$urlSummary})",
        ];
    }

    private function checkSubmitEnabled(): array
    {
        $enabled = (bool) config('workforce.dsn.submit_enabled', false);

        return [
            'key' => 'submit_enabled',
            'label' => 'DSN Submit Enabled',
            'status' => $enabled ? 'green' : 'yellow',
            'detail' => $enabled ? 'Active' : 'Disabled (using NullGateway)',
        ];
    }

    private function checkCredentials(): array
    {
        if (! $this->credentialService->hasCredentials()) {
            return [
                'key' => 'credentials',
                'label' => 'Net-Entreprises Credentials',
                'status' => 'red',
                'detail' => 'Missing — no credentials configured in PlatformSettings',
            ];
        }

        $validation = $this->credentialService->validateFormats();

        if (! empty($validation)) {
            return [
                'key' => 'credentials',
                'label' => 'Net-Entreprises Credentials',
                'status' => 'red',
                'detail' => 'Invalid: ' . implode(', ', $validation),
            ];
        }

        return [
            'key' => 'credentials',
            'label' => 'Net-Entreprises Credentials',
            'status' => 'green',
            'detail' => 'Present and valid format',
        ];
    }

    private function checkGatewayDriver(): array
    {
        $driver = config('workforce.dsn.gateway_driver', 'null');

        $status = match ($driver) {
            'net-entreprises' => 'green',
            'file' => 'yellow',
            default => 'yellow',
        };

        return [
            'key' => 'gateway_driver',
            'label' => 'Gateway Driver',
            'status' => $status,
            'detail' => $driver,
        ];
    }

    private function checkLastSubmit(): array
    {
        $last = DsnDeclaration::whereIn('status', [
            DsnDeclaration::STATUS_SUBMITTED,
            DsnDeclaration::STATUS_ACCEPTED,
            DsnDeclaration::STATUS_REJECTED,
        ])
            ->orderByDesc('submitted_at')
            ->first(['id', 'status', 'submitted_at', 'gateway_status']);

        if (! $last) {
            return [
                'key' => 'last_submit',
                'label' => 'Last Submit',
                'status' => 'yellow',
                'detail' => 'No submissions yet',
            ];
        }

        return [
            'key' => 'last_submit',
            'label' => 'Last Submit',
            'status' => 'green',
            'detail' => sprintf(
                '#%d — %s (%s) at %s',
                $last->id,
                $last->status,
                $last->gateway_status ?? 'n/a',
                $last->submitted_at?->format('Y-m-d H:i'),
            ),
        ];
    }

    private function checkRecentErrors(): array
    {
        $errorsLast24h = DsnDeclaration::whereNotNull('gateway_error_code')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        if ($errorsLast24h === 0) {
            return [
                'key' => 'recent_errors',
                'label' => 'Recent Gateway Errors (24h)',
                'status' => 'green',
                'detail' => 'None',
            ];
        }

        return [
            'key' => 'recent_errors',
            'label' => 'Recent Gateway Errors (24h)',
            'status' => $errorsLast24h >= 5 ? 'red' : 'yellow',
            'detail' => "{$errorsLast24h} error(s) in last 24h",
        ];
    }

    private function checkPendingPolls(): array
    {
        $overdue = DsnDeclaration::where('status', DsnDeclaration::STATUS_SUBMITTED)
            ->where(function ($q) {
                $q->whereNull('next_poll_at')
                    ->orWhere('next_poll_at', '<=', now());
            })
            ->count();

        if ($overdue === 0) {
            return [
                'key' => 'pending_polls',
                'label' => 'Overdue Polls',
                'status' => 'green',
                'detail' => 'None',
            ];
        }

        return [
            'key' => 'pending_polls',
            'label' => 'Overdue Polls',
            'status' => $overdue >= 10 ? 'red' : 'yellow',
            'detail' => "{$overdue} declaration(s) overdue for polling",
        ];
    }

    /**
     * Aggregate individual check statuses into overall status.
     * Any red → red. Any yellow → yellow. All green → green.
     */
    private function aggregateStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array('red', $statuses, true)) {
            return 'red';
        }

        if (in_array('yellow', $statuses, true)) {
            return 'yellow';
        }

        return 'green';
    }
}
