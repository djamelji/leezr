<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\AdminAdvancedMutationService;
use App\Core\Billing\AdminInvoiceMutationService;
use App\Core\Billing\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bulk actions on invoices (ADR-315).
 * Max 50 invoices per action to prevent abuse.
 */
class BillingBulkActionController
{
    public function __construct(
        private readonly AdminInvoiceMutationService $voidService,
        private readonly AdminAdvancedMutationService $advancedService,
    ) {}

    /**
     * POST /api/platform/billing/invoices/bulk-void
     */
    public function bulkVoid(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|max:50',
            'invoice_ids.*' => 'integer|exists:invoices,id',
        ]);

        $results = ['voided' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($validated['invoice_ids'] as $id) {
            try {
                $invoice = Invoice::find($id);

                if (! $invoice || ! in_array($invoice->status, ['open', 'overdue'])) {
                    $results['skipped']++;

                    continue;
                }

                $this->voidService->void($invoice, 'bulk-void-' . $id . '-' . now()->timestamp);

                $results['voided']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Invoice #{$id}: {$e->getMessage()}";
                Log::warning('[billing:bulk-void] failed', ['invoice_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json($results);
    }

    /**
     * POST /api/platform/billing/invoices/bulk-retry
     */
    public function bulkRetry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|max:50',
            'invoice_ids.*' => 'integer|exists:invoices,id',
        ]);

        $results = ['retried' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($validated['invoice_ids'] as $id) {
            try {
                $invoice = Invoice::find($id);

                if (! $invoice || ! in_array($invoice->status, ['open', 'overdue'])) {
                    $results['skipped']++;

                    continue;
                }

                $this->advancedService->retryPayment($invoice, 'bulk-retry-' . $id . '-' . now()->timestamp);

                $results['retried']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Invoice #{$id}: {$e->getMessage()}";
                Log::warning('[billing:bulk-retry] failed', ['invoice_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json($results);
    }
}
