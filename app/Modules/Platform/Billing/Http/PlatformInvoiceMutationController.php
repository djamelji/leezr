<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\AdminInvoiceMutationService;
use App\Core\Billing\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * ADR-135 D2a: Platform admin invoice mutation endpoints.
 *
 * Requires manage_billing permission (write).
 * Three operations: mark-paid-offline, void, update-notes.
 */
class PlatformInvoiceMutationController
{
    public function __construct(
        private readonly AdminInvoiceMutationService $service,
    ) {}

    public function markPaidOffline(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->markPaidOffline(
                $invoice,
                $request->input('idempotency_key'),
            );

            return response()->json([
                'message' => $result['replayed']
                    ? 'Already processed (idempotent replay).'
                    : 'Invoice marked as paid offline.',
                'invoice' => $result['invoice'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->void(
                $invoice,
                $request->input('idempotency_key'),
            );

            return response()->json([
                'message' => $result['replayed']
                    ? 'Already processed (idempotent replay).'
                    : 'Invoice voided.',
                'invoice' => $result['invoice'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function updateNotes(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->service->updateNotes(
                $invoice,
                $request->input('notes'),
            );

            return response()->json([
                'message' => $result['changed']
                    ? 'Invoice notes updated.'
                    : 'No change.',
                'invoice' => $result['invoice'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
