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
use Illuminate\Http\Response;

/**
 * Platform admin billing read endpoints.
 * Requires view_billing permission (read-only).
 */
class PlatformBillingController
{
    public function invoices(Request $request): JsonResponse
    {
        $filters = $this->sanitizeFilters($request, ['company_id', 'status', 'from', 'to', 'search']);
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

    public function invoicePdf(int $id): Response
    {
        $invoice = Invoice::whereNotNull('finalized_at')
            ->where('id', $id)
            ->with(['lines', 'company', 'parentInvoice', 'annexes', 'creditNotes'])
            ->first();

        if (!$invoice) {
            abort(404, 'Invoice not found.');
        }

        $snap = $invoice->billing_snapshot ?? [];
        $locale = $snap['market_locale'] ?? 'fr-FR';
        $payments = \App\Core\Billing\Payment::where('invoice_id', $invoice->id)->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('billing.invoice-pdf', [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'snap' => $snap,
            'locale' => $locale,
            'payments' => $payments,
        ]);

        $filename = ($invoice->number ?: "invoice-{$invoice->id}") . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
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

    /**
     * ADR-328 S8: List scheduled SEPA debits for platform admin.
     */
    public function scheduledDebits(Request $request): JsonResponse
    {
        $query = \App\Core\Billing\ScheduledDebit::with([
            'company:id,name,slug',
            'invoice:id,number',
            'paymentProfile:id,label,method_key',
        ])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('from'), fn ($q, $d) => $q->where('debit_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->where('debit_date', '<=', $d))
            ->when($request->input('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('debit_date');

        return response()->json($query->paginate($request->input('per_page', 25)));
    }
}
