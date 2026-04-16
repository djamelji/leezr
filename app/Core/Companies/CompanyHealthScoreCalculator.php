<?php

namespace App\Core\Companies;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Billing\Invoice;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Support\SupportTicket;
use Carbon\Carbon;

/**
 * ADR-440: Company Intelligence — Health Score Calculator.
 *
 * Calculates a 0-100 health score for a company based on:
 * - Payment health (30%): overdue invoices
 * - Activity (25%): last audit log entry
 * - Profile completeness (15%): member count vs plan usage
 * - Module usage (15%): activated modules ratio
 * - Support (15%): open ticket count
 *
 * Colors: 80-100 = healthy (success), 60-79 = attention (warning), <60 = at-risk (error)
 */
class CompanyHealthScoreCalculator
{
    /**
     * Calculate health score (0-100) for a company.
     */
    public function calculate(Company $company): int
    {
        $scores = [
            'payment' => $this->paymentScore($company) * 0.30,
            'activity' => $this->activityScore($company) * 0.25,
            'profiles' => $this->profileScore($company) * 0.15,
            'modules' => $this->moduleScore($company) * 0.15,
            'support' => $this->supportScore($company) * 0.15,
        ];

        return (int) round(array_sum($scores));
    }

    /**
     * Calculate health score with breakdown for debugging.
     */
    public function calculateWithBreakdown(Company $company): array
    {
        $raw = [
            'payment' => $this->paymentScore($company),
            'activity' => $this->activityScore($company),
            'profiles' => $this->profileScore($company),
            'modules' => $this->moduleScore($company),
            'support' => $this->supportScore($company),
        ];

        $weighted = [
            'payment' => $raw['payment'] * 0.30,
            'activity' => $raw['activity'] * 0.25,
            'profiles' => $raw['profiles'] * 0.15,
            'modules' => $raw['modules'] * 0.15,
            'support' => $raw['support'] * 0.15,
        ];

        $total = (int) round(array_sum($weighted));

        return [
            'score' => $total,
            'label' => self::label($total),
            'color' => self::color($total),
            'breakdown' => $raw,
        ];
    }

    public static function label(int $score): string
    {
        if ($score >= 80) {
            return 'healthy';
        }
        if ($score >= 60) {
            return 'attention';
        }

        return 'at-risk';
    }

    public static function color(int $score): string
    {
        if ($score >= 80) {
            return 'success';
        }
        if ($score >= 60) {
            return 'warning';
        }

        return 'error';
    }

    /**
     * Payment health (30%):
     * 0 overdue invoices → 100
     * 1 overdue → 60
     * 2+ overdue → 20
     * Any overdue > 30 days → 0
     */
    private function paymentScore(Company $company): int
    {
        $overdueInvoices = Invoice::where('company_id', $company->id)
            ->where('status', 'overdue')
            ->get(['id', 'due_at']);

        if ($overdueInvoices->isEmpty()) {
            return 100;
        }

        // Any invoice overdue > 30 days → score 0
        $hasOldOverdue = $overdueInvoices->contains(function ($invoice) {
            return $invoice->due_at && $invoice->due_at->lt(Carbon::now()->subDays(30));
        });

        if ($hasOldOverdue) {
            return 0;
        }

        return $overdueInvoices->count() === 1 ? 60 : 20;
    }

    /**
     * Activity (25%):
     * Last audit log entry <7d → 100
     * <30d → 60
     * >30d → 20
     * >90d or none → 0
     */
    private function activityScore(Company $company): int
    {
        $lastActivity = CompanyAuditLog::where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->value('created_at');

        if (! $lastActivity) {
            return 0;
        }

        $lastActivity = Carbon::parse($lastActivity);
        $daysSince = (int) abs(Carbon::now()->diffInDays($lastActivity));

        if ($daysSince <= 7) {
            return 100;
        }
        if ($daysSince <= 30) {
            return 60;
        }
        if ($daysSince <= 90) {
            return 20;
        }

        return 0;
    }

    /**
     * Profile completeness (15%):
     * Uses member count as a proxy — more members = better adoption.
     * 5+ members → 100, 3-4 → 80, 2 → 60, 1 → 40
     */
    private function profileScore(Company $company): int
    {
        $memberCount = $company->memberships_count
            ?? $company->memberships()->count();

        if ($memberCount >= 5) {
            return 100;
        }
        if ($memberCount >= 3) {
            return 80;
        }
        if ($memberCount >= 2) {
            return 60;
        }

        return 40;
    }

    /**
     * Module usage (15%):
     * % of enabled modules.
     * >50% → 100, 25-50% → 70, >0 → 40, 0 → 20
     */
    private function moduleScore(Company $company): int
    {
        $modules = CompanyModule::where('company_id', $company->id)->get();

        if ($modules->isEmpty()) {
            // No modules configured at all — neutral
            return 70;
        }

        $enabledCount = $modules->where('is_enabled_for_company', true)->count();
        $totalCount = $modules->count();

        if ($totalCount === 0) {
            return 70;
        }

        $ratio = $enabledCount / $totalCount;

        if ($ratio > 0.5) {
            return 100;
        }
        if ($ratio > 0.25) {
            return 70;
        }
        if ($enabledCount > 0) {
            return 40;
        }

        return 20;
    }

    /**
     * Support (15%):
     * 0 open tickets → 100
     * 1-2 → 80
     * 3+ → 40
     */
    private function supportScore(Company $company): int
    {
        $openTickets = SupportTicket::where('company_id', $company->id)
            ->where('status', 'open')
            ->count();

        if ($openTickets === 0) {
            return 100;
        }
        if ($openTickets <= 2) {
            return 80;
        }

        return 40;
    }
}
