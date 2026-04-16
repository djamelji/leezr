<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogController
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailLog::orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($templateKey = $request->query('template_key')) {
            $query->where('template_key', $templateKey);
        }
        if ($category = $request->query('category')) {
            $query->where('template_key', 'LIKE', "{$category}.%");
        }
        if ($companyId = $request->query('company_id')) {
            $query->where('company_id', $companyId);
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', $dateTo);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('recipient_email', 'LIKE', "%{$search}%")
                ->orWhere('subject', 'LIKE', "%{$search}%")
            );
        }

        $logs = $query->paginate(20);

        // Stats
        $sent24h = EmailLog::where('status', 'sent')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $failed24h = EmailLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $total24h = $sent24h + $failed24h;
        $successRate = $total24h > 0 ? round(($sent24h / $total24h) * 100, 1) : 100;

        return response()->json([
            'data' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total' => $logs->total(),
            'stats' => [
                'sent_24h' => $sent24h,
                'failed_24h' => $failed24h,
                'success_rate' => $successRate,
            ],
        ]);
    }

    public function retry(int $id): JsonResponse
    {
        $log = EmailLog::findOrFail($id);

        if ($log->status !== 'failed') {
            return response()->json(['message' => 'Can only retry failed emails.'], 422);
        }

        $newLog = app(EmailService::class)->retry($log);

        return response()->json([
            'message' => 'Email queued for retry.',
            'log' => $newLog,
        ]);
    }

    /**
     * Catalog of all email templates — read-only.
     */
    public function templates(): JsonResponse
    {
        $templates = [
            ['key' => 'billing.trial_started', 'name' => 'Trial Started', 'trigger' => 'Company registration', 'description' => 'Welcome email when a new trial begins'],
            ['key' => 'billing.trial_expiring', 'name' => 'Trial Expiring', 'trigger' => 'Daily cron (billing:check-trial-expiring)', 'description' => 'Reminder before trial ends'],
            ['key' => 'billing.trial_converted', 'name' => 'Trial Converted', 'trigger' => 'Trial expiration', 'description' => 'Confirmation when trial converts to paid'],
            ['key' => 'billing.payment_received', 'name' => 'Payment Received', 'trigger' => 'Invoice finalization', 'description' => 'Payment confirmation with amount'],
            ['key' => 'billing.payment_failed', 'name' => 'Payment Failed', 'trigger' => 'Dunning process', 'description' => 'Alert when payment cannot be processed'],
            ['key' => 'billing.payment_method_expiring', 'name' => 'Payment Method Expiring', 'trigger' => 'Daily cron (billing:check-expiring-cards)', 'description' => 'Reminder to update expiring card'],
            ['key' => 'billing.invoice_created', 'name' => 'Invoice Created', 'trigger' => 'Invoice finalization', 'description' => 'New invoice notification with amount'],
            ['key' => 'billing.plan_changed', 'name' => 'Plan Changed', 'trigger' => 'Plan change execution', 'description' => 'Confirmation of plan upgrade/downgrade'],
            ['key' => 'billing.addon_activated', 'name' => 'Addon Activated', 'trigger' => 'Module enable with billing', 'description' => 'Module activation with invoice amount'],
            ['key' => 'billing.account_suspended', 'name' => 'Account Suspended', 'trigger' => 'Dunning stage transition', 'description' => 'Account suspended due to unpaid invoices'],
            ['key' => 'billing.critical_alert', 'name' => 'Critical Alert', 'trigger' => 'Critical severity audit log', 'description' => 'Critical billing event alert for admins'],
        ];

        return response()->json(['templates' => $templates]);
    }
}
