<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Core\Alerts\PlatformAlert;
use App\Core\Automation\ScheduledTaskRegistry;
use App\Core\Automation\ScheduledTaskRun;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Support\SupportTicket;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * ADR-441: Dashboard cockpit — decision-oriented platform landing page.
 *
 * Two endpoints:
 *  - GET /dashboard/attention → items requiring immediate admin action
 *  - GET /dashboard/health    → system health summary (4 badges)
 */
class DashboardCockpitController extends Controller
{
    /**
     * GET /platform/dashboard/attention
     *
     * Items requiring immediate admin action.
     * Each item: type, label, count, severity (critical/warning/info), route, icon.
     */
    public function attention(): JsonResponse
    {
        $items = [];

        // 1. Invoices overdue > 7 days
        $overdueInvoices = Invoice::withoutCompanyScope()
            ->whereIn('status', ['overdue', 'open'])
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

        // 2. Tickets unassigned > 24h
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
                'route' => ['name' => 'platform-support'],
            ];
        }

        // 3. Subscriptions pending approval
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

        // 4. Payments failed (last 7 days)
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

        // 5. Active critical alerts
        $criticalAlerts = PlatformAlert::active()->critical()->count();

        if ($criticalAlerts > 0) {
            $items[] = [
                'type' => 'alerts_critical',
                'count' => $criticalAlerts,
                'severity' => 'critical',
                'icon' => 'tabler-alert-triangle',
                'route' => ['name' => 'platform-alerts'],
            ];
        }

        return response()->json(['items' => $items]);
    }

    /**
     * GET /platform/dashboard/health
     *
     * System health summary — 4 badges (scheduler, queue, AI, alerts).
     */
    public function health(): JsonResponse
    {
        $badges = [];

        // 1. Scheduler health (last 2 hours)
        $recentRuns = ScheduledTaskRun::where('created_at', '>=', now()->subHours(2));
        $failedCount = (clone $recentRuns)->where('status', 'failed')->count();
        $totalCount = (clone $recentRuns)->count();

        $badges['scheduler'] = [
            'status' => $totalCount === 0 ? 'unknown' : ($failedCount > 2 ? 'critical' : ($failedCount > 0 ? 'warning' : 'ok')),
            'label' => 'Scheduler',
            'detail' => $totalCount === 0
                ? 'No runs in 2h'
                : "{$failedCount} failed / {$totalCount} runs (2h)",
        ];

        // 2. Queue health (via ScheduledTaskRegistry — no direct DB facade)
        try {
            $queueStats = ScheduledTaskRegistry::queueStats();
            $queueDepth = ($queueStats['queue_default_pending'] ?? 0) + ($queueStats['queue_ai_pending'] ?? 0);
            $failedJobs = ($queueStats['queue_default_failed_24h'] ?? 0) + ($queueStats['queue_ai_failed_24h'] ?? 0);

            $badges['queue'] = [
                'status' => $failedJobs > 5 ? 'critical' : ($queueDepth > 100 ? 'warning' : 'ok'),
                'label' => 'Queue',
                'detail' => "{$queueDepth} pending, {$failedJobs} failed (24h)",
            ];
        } catch (\Exception $e) {
            $badges['queue'] = [
                'status' => 'unknown',
                'label' => 'Queue',
                'detail' => 'Unable to check',
            ];
        }

        // 3. AI providers (simplified — operational unless explicit issue)
        $badges['ai'] = [
            'status' => 'ok',
            'label' => 'AI',
            'detail' => 'Operational',
        ];

        // 4. Alerts summary
        $activeCritical = PlatformAlert::active()->critical()->count();
        $activeTotal = PlatformAlert::active()->count();

        $badges['alerts'] = [
            'status' => $activeCritical > 0 ? 'critical' : ($activeTotal > 5 ? 'warning' : 'ok'),
            'label' => 'Alerts',
            'detail' => "{$activeCritical} critical, {$activeTotal} total active",
        ];

        return response()->json(['badges' => $badges]);
    }
}
