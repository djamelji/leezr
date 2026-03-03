<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\AdminAdvancedMutationService;
use App\Core\Billing\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * ADR-136 D2c: Advanced platform admin invoice mutation endpoints.
 *
 * Requires manage_billing permission (write).
 * Five operations: refund, retry-payment, dunning-transition,
 * credit-note, write-off.
 */
class PlatformAdvancedMutationController
{
    public function __construct(
        private readonly AdminAdvancedMutationService $service,
    ) {}

    public function refund(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->refund(
                $invoice,
                $request->integer('amount'),
                $request->input('reason'),
                $request->input('idempotency_key'),
            );

            return response()->json([
                'message' => $result['replayed']
                    ? 'Already processed (idempotent replay).'
                    : 'Refund credit note issued.',
                'credit_note' => $result['credit_note'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function retryPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->retryPayment(
                $invoice,
                $request->input('idempotency_key'),
            );

            $messages = [
                'paid' => 'Payment succeeded — invoice is now paid.',
                'retried' => 'Retry scheduled — next attempt queued.',
                'exhausted' => 'Max retries exhausted — invoice marked uncollectible.',
                'skipped' => 'Invoice was not in a retryable state.',
            ];

            return response()->json([
                'message' => $messages[$result['result']] ?? 'Retry processed.',
                'result' => $result['result'],
                'invoice' => $result['invoice'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function forceDunningTransition(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'target_status' => ['required', 'string', 'in:overdue,uncollectible'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->forceDunningTransition(
                $invoice,
                $request->input('target_status'),
                $request->input('idempotency_key'),
            );

            return response()->json([
                'message' => $result['replayed']
                    ? 'Already processed (idempotent replay).'
                    : 'Dunning transition applied.',
                'invoice' => $result['invoice'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function issueCreditNote(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'apply_to_wallet' => ['required', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->issueCreditNote(
                $invoice,
                $request->integer('amount'),
                $request->input('reason'),
                $request->boolean('apply_to_wallet'),
                $request->input('idempotency_key'),
            );

            return response()->json([
                'message' => $result['replayed']
                    ? 'Already processed (idempotent replay).'
                    : 'Credit note issued.',
                'credit_note' => $result['credit_note'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function writeOff(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->service->writeOff(
                $invoice,
                $request->input('idempotency_key'),
            );

            return response()->json([
                'message' => $result['replayed']
                    ? 'Already processed (idempotent replay).'
                    : 'Invoice written off.',
                'invoice' => $result['invoice'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
