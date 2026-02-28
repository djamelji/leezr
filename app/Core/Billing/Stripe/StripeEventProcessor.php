<?php

namespace App\Core\Billing\Stripe;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CreditNote;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;

/**
 * Processes Stripe webhook events into billing state changes (ADR-138 D3b).
 *
 * Invoice-first: every handler requires a resolved finalized invoice.
 * No Payment row is created without a valid invoice link.
 */
class StripeEventProcessor
{
    private const HANDLED_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function process(array $payload): WebhookHandlingResult
    {
        $eventType = $payload['type'] ?? null;

        if (! in_array($eventType, self::HANDLED_EVENTS, true)) {
            return new WebhookHandlingResult(handled: false);
        }

        $object = $payload['data']['object'] ?? null;

        if (! $object) {
            return new WebhookHandlingResult(handled: false, error: 'Missing data.object in payload.');
        }

        return match ($eventType) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($object),
            'charge.refunded' => $this->handleChargeRefunded($object),
        };
    }

    // ── Handlers ─────────────────────────────────────────

    private function handlePaymentSucceeded(array $intent): WebhookHandlingResult
    {
        $intentId = $intent['id'];
        $metadata = $intent['metadata'] ?? [];
        $stripeCustomerId = $intent['customer'] ?? null;

        $companyId = $this->resolveCompanyId($metadata, $stripeCustomerId);
        if (! $companyId) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve company.');
        }

        $invoice = $this->resolveInvoice($metadata, $companyId);
        if (! $invoice) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve finalized invoice.');
        }

        $amount = $intent['amount_received'] ?? $intent['amount'];
        $currency = strtoupper($intent['currency'] ?? 'EUR');
        $subscriptionId = $this->resolveSubscriptionId($invoice, $companyId);

        $payment = Payment::updateOrCreate(
            ['provider_payment_id' => $intentId],
            [
                'company_id' => $companyId,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'succeeded',
                'provider' => 'stripe',
                'metadata' => [
                    'stripe_customer_id' => $stripeCustomerId,
                    'stripe_payment_method' => $intent['payment_method'] ?? null,
                ],
            ],
        );

        // Mark invoice paid (only from open/overdue)
        if (in_array($invoice->status, ['open', 'overdue'], true)) {
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        // Ledger: record payment received (ADR-142 D3f)
        try {
            LedgerService::recordPaymentReceived($payment);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[ledger] payment received recording failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->audit->logPlatform(
            AuditAction::WEBHOOK_PAYMENT_SYNCED,
            'payment',
            (string) $payment->id,
            [
                'actorType' => 'system',
                'severity' => 'info',
                'metadata' => [
                    'provider_payment_id' => $intentId,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: 'payment_synced');
    }

    private function handlePaymentFailed(array $intent): WebhookHandlingResult
    {
        $intentId = $intent['id'];
        $metadata = $intent['metadata'] ?? [];
        $stripeCustomerId = $intent['customer'] ?? null;

        $companyId = $this->resolveCompanyId($metadata, $stripeCustomerId);
        if (! $companyId) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve company.');
        }

        $invoice = $this->resolveInvoice($metadata, $companyId);
        if (! $invoice) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve finalized invoice.');
        }

        $amount = $intent['amount'] ?? 0;
        $currency = strtoupper($intent['currency'] ?? 'EUR');
        $subscriptionId = $this->resolveSubscriptionId($invoice, $companyId);

        $payment = Payment::updateOrCreate(
            ['provider_payment_id' => $intentId],
            [
                'company_id' => $companyId,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'failed',
                'provider' => 'stripe',
                'metadata' => [
                    'stripe_customer_id' => $stripeCustomerId,
                    'failure_code' => $intent['last_payment_error']['code'] ?? null,
                    'failure_message' => $intent['last_payment_error']['message'] ?? null,
                ],
            ],
        );

        // Mark invoice overdue (only from open — never regress paid)
        if ($invoice->status === 'open') {
            $invoice->update(['status' => 'overdue']);
        }

        $this->audit->logPlatform(
            AuditAction::WEBHOOK_PAYMENT_FAILED,
            'payment',
            (string) $payment->id,
            [
                'actorType' => 'system',
                'severity' => 'warning',
                'metadata' => [
                    'provider_payment_id' => $intentId,
                    'invoice_id' => $invoice->id,
                    'failure_code' => $intent['last_payment_error']['code'] ?? null,
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: 'payment_failed');
    }

    private function handleChargeRefunded(array $charge): WebhookHandlingResult
    {
        $chargeId = $charge['id'];
        $paymentIntentId = $charge['payment_intent'] ?? null;

        if (! $paymentIntentId) {
            return new WebhookHandlingResult(handled: false, error: 'Missing payment_intent on charge.');
        }

        $payment = Payment::where('provider_payment_id', $paymentIntentId)->first();

        if (! $payment || ! $payment->invoice_id || ! $payment->company_id) {
            return new WebhookHandlingResult(handled: false, error: 'No matching payment with invoice.');
        }

        $company = Company::find($payment->company_id);
        if (! $company) {
            return new WebhookHandlingResult(handled: false, error: 'Company not found.');
        }

        $refunds = $charge['refunds']['data'] ?? [];
        $processedCount = 0;

        foreach ($refunds as $refund) {
            $refundId = $refund['id'];
            $refundAmount = $refund['amount'];

            // Idempotency: one credit note per (invoice_id, provider_refund_id)
            $exists = CreditNote::where('invoice_id', $payment->invoice_id)
                ->whereJsonContains('metadata->provider_refund_id', $refundId)
                ->exists();

            if ($exists) {
                continue;
            }

            $cn = CreditNoteIssuer::createDraft(
                company: $company,
                amount: $refundAmount,
                reason: "Stripe refund {$refundId}",
                invoiceId: $payment->invoice_id,
                metadata: [
                    'provider_refund_id' => $refundId,
                    'provider_charge_id' => $chargeId,
                    'type' => 'stripe_refund',
                ],
            );

            CreditNoteIssuer::issue($cn);
            $processedCount++;
        }

        // Update payment status to refunded
        $payment->update(['status' => 'refunded']);

        $this->audit->logPlatform(
            AuditAction::WEBHOOK_REFUND_SYNCED,
            'payment',
            (string) $payment->id,
            [
                'actorType' => 'system',
                'severity' => 'critical',
                'metadata' => [
                    'provider_charge_id' => $chargeId,
                    'invoice_id' => $payment->invoice_id,
                    'refunds_processed' => $processedCount,
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: 'refund_synced');
    }

    // ── Resolvers ────────────────────────────────────────

    private function resolveCompanyId(array $metadata, ?string $stripeCustomerId): ?int
    {
        // Primary: metadata.company_id
        $companyId = isset($metadata['company_id']) ? (int) $metadata['company_id'] : null;

        if ($companyId && Company::where('id', $companyId)->exists()) {
            return $companyId;
        }

        // Fallback: Stripe customer → CompanyPaymentCustomer
        if ($stripeCustomerId) {
            return CompanyPaymentCustomer::where('provider_key', 'stripe')
                ->where('provider_customer_id', $stripeCustomerId)
                ->first()
                ?->company_id;
        }

        return null;
    }

    private function resolveInvoice(array $metadata, int $companyId): ?Invoice
    {
        // Primary: metadata.invoice_id
        if (isset($metadata['invoice_id'])) {
            $invoice = Invoice::where('id', (int) $metadata['invoice_id'])
                ->where('company_id', $companyId)
                ->first();

            if ($invoice && $invoice->isFinalized()) {
                return $invoice;
            }
        }

        // Fallback: metadata.invoice_number
        if (isset($metadata['invoice_number'])) {
            $invoice = Invoice::where('number', $metadata['invoice_number'])
                ->where('company_id', $companyId)
                ->first();

            if ($invoice && $invoice->isFinalized()) {
                return $invoice;
            }
        }

        return null;
    }

    private function resolveSubscriptionId(Invoice $invoice, int $companyId): ?int
    {
        if ($invoice->subscription_id) {
            return $invoice->subscription_id;
        }

        return Subscription::where('company_id', $companyId)
            ->where('provider', 'stripe')
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->first()
            ?->id;
    }
}
