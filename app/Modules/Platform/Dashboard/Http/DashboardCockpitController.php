<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Core\Ai\ReadModels\AiHealthReadService;
use App\Core\Alerts\PlatformAlert;
use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Support\SupportTicket;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-441 / ADR-749: Dashboard cockpit — decision-oriented platform landing.
 *
 * Two endpoints:
 *  - GET /dashboard/attention → items requiring immediate admin action
 *  - GET /dashboard/health    → system health summary (badges)
 *
 * ADR-749 (P0 — security): the route group only requires `view_dashboard`, but the
 * payload aggregates data from several sensitivity domains. Each slice is therefore
 * gated INSIDE the controller against the SAME fine-grained permission as its widget
 * counterpart (mirrors DashboardWidgetController::batchResolve). A user never receives
 * a field they are not authorised to read — not merely hidden in the UI, absent from
 * the JSON. Domains → permissions:
 *   - billing (invoices, subscriptions, payments) → view_billing
 *   - support (tickets)                           → manage_support
 *   - platform alerts                             → view_alerts
 *   - system health (scheduler, queue, ai)        → view_system_health
 */
class DashboardCockpitController extends Controller
{
    /**
     * GET /platform/dashboard/attention
     *
     * Items requiring immediate admin action — permission-scoped (ADR-749).
     * Each item: type, count, severity (critical/warning/info), route, icon.
     */
    public function attention(Request $request): JsonResponse
    {
        $user = $request->user('platform');
        $can = fn (string $perm): bool => $user !== null && $user->hasPermission($perm);

        $items = [];

        // ── Billing domain (view_billing) ──
        if ($can('view_billing')) {
            // Invoices genuinely overdue > 7 days: still unpaid (paid_at NULL) AND past due.
            // The paid_at guard excludes any invoice already settled but left on a stale status.
            $overdueInvoices = Invoice::withoutCompanyScope()
                ->whereIn('status', ['overdue', 'open'])
                ->whereNull('paid_at')
                ->where('due_at', '<', now()->subDays(7))
                ->count();

            if ($overdueInvoices > 0) {
                $items[] = [
                    'type' => 'invoices_overdue',
                    'count' => $overdueInvoices,
                    'severity' => $overdueInvoices > 5 ? 'critical' : 'warning',
                    'icon' => 'tabler-file-invoice',
                    'route' => ['name' => 'platform-billing-tab', 'params' => ['tab' => 'invoices']],
                ];
            }

            // Subscriptions awaiting ADMIN APPROVAL (status = pending).
            $pendingSubs = Subscription::withoutCompanyScope()
                ->where('status', 'pending')
                ->count();

            if ($pendingSubs > 0) {
                $items[] = [
                    'type' => 'subscriptions_pending',
                    'count' => $pendingSubs,
                    'severity' => 'warning',
                    'icon' => 'tabler-clock-check',
                    'route' => ['name' => 'platform-billing-tab', 'params' => ['tab' => 'subscriptions']],
                ];
            }

            // Subscriptions awaiting PAYMENT (status = pending_payment) — a DISTINCT action
            // (a payment must clear), not an approval. Surfaced separately so the operator
            // knows which lever to pull (ADR-749).
            $pendingPaymentSubs = Subscription::withoutCompanyScope()
                ->where('status', 'pending_payment')
                ->count();

            if ($pendingPaymentSubs > 0) {
                $items[] = [
                    'type' => 'subscriptions_pending_payment',
                    'count' => $pendingPaymentSubs,
                    'severity' => 'warning',
                    'icon' => 'tabler-credit-card-refund',
                    'route' => ['name' => 'platform-billing-tab', 'params' => ['tab' => 'subscriptions']],
                ];
            }

            // Payments failed (last 7 days)
            $failedPayments = Payment::withoutCompanyScope()
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            if ($failedPayments > 0) {
                $items[] = [
                    'type' => 'payments_failed',
                    'count' => $failedPayments,
                    'severity' => $failedPayments > 3 ? 'critical' : 'warning',
                    'icon' => 'tabler-credit-card-off',
                    'route' => ['name' => 'platform-billing-tab', 'params' => ['tab' => 'payments']],
                ];
            }
        }

        // ── Support domain (manage_support) ──
        if ($can('manage_support')) {
            $unassignedTickets = SupportTicket::withoutCompanyScope()
                ->whereNull('assigned_to_platform_user_id')
                ->whereNotIn('status', ['closed', 'resolved'])
                ->where('created_at', '<', now()->subDay())
                ->count();

            if ($unassignedTickets > 0) {
                $items[] = [
                    'type' => 'tickets_unassigned',
                    'count' => $unassignedTickets,
                    'severity' => 'warning',
                    'icon' => 'tabler-headset',
                    'route' => ['name' => 'platform-communications-tab', 'params' => ['tab' => 'support']] /* ADR-855 : route renamed (ADR-801.1), the old name did not resolve */,
                ];
            }
        }

        // ── Platform alerts (view_alerts) ──
        if ($can('view_alerts')) {
            $criticalAlerts = PlatformAlert::active()->critical()->count();

            if ($criticalAlerts > 0) {
                $items[] = [
                    'type' => 'alerts_critical',
                    'count' => $criticalAlerts,
                    'severity' => 'critical',
                    'icon' => 'tabler-alert-triangle',
                    'route' => ['name' => 'platform-operations-tab', 'params' => ['tab' => 'alerts']] /* ADR-855 : idem */,
                ];
            }
        }

        return response()->json(['items' => $items]);
    }

    /**
     * GET /platform/dashboard/health
     *
     * System health summary — permission-scoped badges (ADR-749). Scheduler liveness
     * is read from the AUTHORITATIVE per-minute heartbeat (globalHealth), never from
     * business-task run history (which is silent when the scheduler is simply idle).
     */
    public function health(Request $request): JsonResponse
    {
        $user = $request->user('platform');
        $can = fn (string $perm): bool => $user !== null && $user->hasPermission($perm);

        $badges = [];

        // ── Ops / system health (view_system_health) ──
        if ($can('view_system_health')) {
            // 1. Scheduler — authoritative heartbeat (ADR-749). "No task ran" is NOT proof
            // of a failure; only a stale/absent heartbeat is. We never fabricate 'ok'.
            $sched = ScheduledTaskRegistry::globalHealth();
            $badges['scheduler'] = [
                'status' => $this->schedulerBadgeStatus($sched['status']),
                'label' => 'Scheduler',
                'detail' => $this->schedulerBadgeDetail($sched),
            ];

            // 2. Queue health
            try {
                $queueStats = ScheduledTaskRegistry::queueStats();
                $queueDepth = ($queueStats['queue_default_pending'] ?? 0) + ($queueStats['queue_ai_pending'] ?? 0);
                $failedJobs = ($queueStats['queue_default_failed_24h'] ?? 0) + ($queueStats['queue_ai_failed_24h'] ?? 0);

                $badges['queue'] = [
                    'status' => $failedJobs > 5 ? 'critical' : ($queueDepth > 100 ? 'warning' : 'ok'),
                    'label' => 'Queue',
                    'detail' => "{$queueDepth} pending, {$failedJobs} failed (24h)",
                ];
            } catch (\Throwable) {
                $badges['queue'] = ['status' => 'unknown', 'label' => 'Queue', 'detail' => 'Unable to check'];
            }

            // 3. AI providers — real probe (ADR-749: no more hardcoded 'ok').
            try {
                $ai = AiHealthReadService::check();
                $badges['ai'] = [
                    'status' => $ai['healthy'] ? 'ok' : (($ai['provider']['healthy'] ?? true) ? 'warning' : 'critical'),
                    'label' => 'AI',
                    'detail' => ($ai['provider']['key'] ?? 'provider').' · '.($ai['provider']['status'] ?? 'unknown'),
                ];
            } catch (\Throwable) {
                $badges['ai'] = ['status' => 'unknown', 'label' => 'AI', 'detail' => 'Unable to check'];
            }
        }

        // ── Platform alerts (view_alerts) ──
        if ($can('view_alerts')) {
            $activeCritical = PlatformAlert::active()->critical()->count();
            $activeTotal = PlatformAlert::active()->count();

            $badges['alerts'] = [
                'status' => $activeCritical > 0 ? 'critical' : ($activeTotal > 5 ? 'warning' : 'ok'),
                'label' => 'Alerts',
                'detail' => "{$activeCritical} critical, {$activeTotal} total active",
            ];
        }

        return response()->json(['badges' => $badges]);
    }

    /**
     * Map the authoritative scheduler liveness to a badge status. Absence of a heartbeat
     * (unknown) stays DETECTABLE (warning), never silently promoted to 'ok'; a stale
     * heartbeat beyond the dead threshold is the only real 'critical'.
     */
    private function schedulerBadgeStatus(string $global): string
    {
        return match ($global) {
            'ok' => 'ok',
            'silent' => 'warning',
            'dead' => 'critical',
            default => 'unknown', // no heartbeat observed yet — detectable, not a false panic
        };
    }

    /** @param array{status:string,heartbeat_age_seconds:?int,business_runs_recorded:bool} $s */
    private function schedulerBadgeDetail(array $s): string
    {
        return match ($s['status']) {
            'ok' => 'Heartbeat '.($s['heartbeat_age_seconds'] ?? 0).'s ago',
            'silent' => 'Heartbeat late ('.($s['heartbeat_age_seconds'] ?? '?').'s)',
            'dead' => 'Heartbeat stale ('.($s['heartbeat_age_seconds'] ?? '?').'s) — cron may be down',
            default => 'No heartbeat observed yet',
        };
    }
}
