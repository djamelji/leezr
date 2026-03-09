<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\InvoiceBatchPayService;
use App\Core\Billing\Invoice;
use App\Core\Billing\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-257: Batch invoice payment via Stripe Payment Element.
 *
 * Endpoints:
 *   GET  /billing/invoices/outstanding  → list unpaid invoices
 *   POST /billing/invoices/pay          → create PaymentIntent (after wallet deduction)
 *   POST /billing/invoices/pay/confirm  → confirm after Stripe payment
 */
class InvoiceBatchPayController
{
    /**
     * List all outstanding invoices for batch payment selection.
     */
    public function listOutstanding(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $invoices = Invoice::where('company_id', $company->id)
            ->whereIn('status', ['open', 'overdue', 'uncollectible'])
            ->where('amount_due', '>', 0)
            ->whereNotNull('finalized_at')
            ->orderBy('due_at')
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'status' => $inv->status,
                'amount' => $inv->amount,
                'amount_due' => $inv->amount_due,
                'currency' => $inv->currency,
                'due_at' => $inv->due_at,
                'period_start' => $inv->period_start,
                'period_end' => $inv->period_end,
            ]);

        $walletBalance = WalletLedger::balance($company);
        $currency = WalletLedger::ensureWallet($company)->currency;

        return response()->json([
            'invoices' => $invoices,
            'wallet_balance' => $walletBalance,
            'currency' => $currency,
        ]);
    }

    /**
     * Create a PaymentIntent for the selected invoices.
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer'],
            'use_wallet' => ['sometimes', 'boolean'],
        ]);

        // P0: Ownership guard — every invoice must belong to this company
        $foreignCount = Invoice::whereIn('id', $validated['invoice_ids'])
            ->where('company_id', '!=', $company->id)
            ->count();

        if ($foreignCount > 0) {
            return response()->json(['message' => 'Forbidden: invoice does not belong to this company.'], 403);
        }

        try {
            $result = InvoiceBatchPayService::createPaymentIntent(
                company: $company,
                invoiceIds: $validated['invoice_ids'],
                useWallet: $validated['use_wallet'] ?? true,
                userId: $request->user()?->id,
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Confirm payment after Stripe PaymentIntent succeeds.
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'payment_intent_id' => ['required', 'string'],
            'save_card' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = InvoiceBatchPayService::confirmPayment(
                company: $company,
                paymentIntentId: $validated['payment_intent_id'],
                userId: $request->user()?->id,
                saveCard: $validated['save_card'] ?? false,
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
