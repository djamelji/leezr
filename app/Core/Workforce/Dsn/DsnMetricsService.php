<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Workforce\DsnDeclaration;

/**
 * Read-only DSN gateway metrics from DsnDeclaration model.
 *
 * No external dependency (Prometheus, etc.) — pure DB queries
 * over the workforce_dsn_declarations table.
 *
 * Sprint 7.5 — ADR-533
 */
class DsnMetricsService
{
    /**
     * Get all DSN gateway metrics.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'dsn_declarations_submitted_total' => $this->countByStatus(DsnDeclaration::STATUS_SUBMITTED),
            'dsn_declarations_accepted_total' => $this->countByStatus(DsnDeclaration::STATUS_ACCEPTED),
            'dsn_declarations_rejected_total' => $this->countByStatus(DsnDeclaration::STATUS_REJECTED),
            'dsn_poll_attempts_total' => $this->totalPollAttempts(),
            'dsn_gateway_errors_total' => $this->totalGatewayErrors(),
            'dsn_average_acceptance_delay_minutes' => $this->averageAcceptanceDelayMinutes(),
            'collected_at' => now()->toIso8601String(),
        ];
    }

    public function countByStatus(string $status): int
    {
        return DsnDeclaration::where('status', $status)->count();
    }

    /**
     * Sum of all attempt_count across submitted declarations.
     * Each poll attempt increments attempt_count.
     */
    public function totalPollAttempts(): int
    {
        return (int) DsnDeclaration::whereIn('status', [
            DsnDeclaration::STATUS_SUBMITTED,
            DsnDeclaration::STATUS_ACCEPTED,
            DsnDeclaration::STATUS_REJECTED,
        ])->sum('attempt_count');
    }

    /**
     * Count declarations with a gateway error (non-null gateway_error_code).
     */
    public function totalGatewayErrors(): int
    {
        return DsnDeclaration::whereNotNull('gateway_error_code')->count();
    }

    /**
     * Average delay between submitted_at and the moment the declaration
     * was accepted (status became 'accepted'). Uses updated_at as proxy.
     *
     * Returns null if no accepted declarations exist.
     *
     * Uses PHP-side calculation for SQLite compatibility.
     */
    public function averageAcceptanceDelayMinutes(): ?float
    {
        $rows = DsnDeclaration::where('status', DsnDeclaration::STATUS_ACCEPTED)
            ->whereNotNull('submitted_at')
            ->get(['submitted_at', 'updated_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $totalMinutes = $rows->sum(fn ($r) => abs($r->submitted_at->diffInMinutes($r->updated_at)));

        return round($totalMinutes / $rows->count(), 1);
    }
}
