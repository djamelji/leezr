<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\BillingExpectedConfirmation;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\PlatformBillingReadService;
use App\Core\Billing\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform admin billing read endpoints.
 * Requires view_billing permission (read-only).
 */
class PlatformBillingController
{
    public function invoices(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status', 'from', 'to']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::invoices($filters, $perPage),
        );
    }

    public function invoiceDetail(int $id): JsonResponse
    {
        $invoice = PlatformBillingReadService::invoiceDetail($id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    public function payments(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::payments($filters, $perPage),
        );
    }

    public function creditNotes(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::creditNotes($filters, $perPage),
        );
    }

    public function wallets(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::wallets($filters, $perPage),
        );
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['status', 'plan_key', 'company_id']);
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::subscriptions($filters, $perPage),
        );
    }

    public function dunning(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            PlatformBillingReadService::dunningInvoices($perPage),
        );
    }

    public function recoveryStatus(): JsonResponse
    {
        $deadLetters = BillingWebhookDeadLetter::where('status', 'pending')->count();

        $stuckCheckouts = BillingCheckoutSession::where('status', 'created')
            ->where('created_at', '<=', now()->subHour())
            ->count();

        $overdueConfirmations = BillingExpectedConfirmation::where('status', 'pending')
            ->where('expected_by', '<=', now())
            ->count();

        $overdueInvoices = Invoice::where('status', 'overdue')->count();

        $pastDueSubs = Subscription::where('status', 'past_due')->count();

        $pendingApprovals = Subscription::where('status', 'pending')->count();

        $heartbeats = BillingJobHeartbeat::all()->map(fn ($hb) => [
            'job_key' => $hb->job_key,
            'last_status' => $hb->last_status,
            'last_finished_at' => $hb->last_finished_at?->toISOString(),
            'last_error' => $hb->last_error,
        ])->toArray();

        $anomalies = ($deadLetters > 0 ? 1 : 0)
            + ($stuckCheckouts > 0 ? 1 : 0)
            + ($overdueConfirmations > 0 ? 1 : 0);

        return response()->json([
            'dead_letters' => $deadLetters,
            'stuck_checkouts' => $stuckCheckouts,
            'overdue_confirmations' => $overdueConfirmations,
            'overdue_invoices' => $overdueInvoices,
            'past_due_subscriptions' => $pastDueSubs,
            'pending_approvals' => $pendingApprovals,
            'heartbeats' => $heartbeats,
            'anomalies' => $anomalies,
            'status' => $anomalies > 0 ? 'warning' : 'ok',
        ]);
    }

    private function sanitizeFilters(Request $request, array $allowed): array
    {
        $filters = $request->only($allowed);

        if (isset($filters['company_id'])) {
            $filters['company_id'] = (int) $filters['company_id'];
        }

        return $filters;
    }
}
