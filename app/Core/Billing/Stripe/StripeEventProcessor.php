<?php

namespace App\Core\Billing\Stripe;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingExpectedConfirmation;
use App\Core\Billing\CheckoutSessionActivator;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\CreditNote;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceBatchPayService;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes Stripe webhook events into billing state changes (ADR-138 D3b).
 *
 * Invoice-first: every handler requires a resolved finalized invoice.
 * No Payment row is created without a valid invoice link.
 */
class StripeEventProcessor
{
    private const HANDLED_EVENTS = [
        'checkout.session.completed',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'charge.dispute.created',
        'setup_intent.succeeded',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function process(array $payload): WebhookHandlingResult
    {
        $eventType = $payload['type'] ?? null;

        Log::channel('billing')->info('Stripe webhook received', [
            'type' => $eventType,
            'id' => $payload['id'] ?? null,
        ]);

        if (! in_array($eventType, self::HANDLED_EVENTS, true)) {
            return new WebhookHandlingResult(handled: false);
        }

        $object = $payload['data']['object'] ?? null;

        if (! $object) {
            return new WebhookHandlingResult(handled: false, error: 'Missing data.object in payload.');
        }

        // ADR-228: Resolve expected confirmation if one matches
        $this->resolveExpectedConfirmation($eventType, $object);

        return match ($eventType) {
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($object),
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($object),
            'charge.refunded' => $this->handleChargeRefunded($object),
            'charge.dispute.created' => $this->handleDisputeCreated($object),
            'setup_intent.succeeded' => $this->handleSetupIntentSucceeded($object),
        };
    }

    // ── Handlers ─────────────────────────────────────────

    private function handleCheckoutSessionCompleted(array $session): WebhookHandlingResult
    {
        // ADR-229: Delegate to shared activator (triple recovery)
        $result = CheckoutSessionActivator::activateFromStripeSession($session);

        if (! $result->activated && ! $result->idempotent) {
            return new WebhookHandlingResult(handled: false, error: $result->reason);
        }

        $metadata = $session['metadata'] ?? [];
        $subscriptionId = (int) ($metadata['subscription_id'] ?? 0);
        $companyId = (int) ($metadata['company_id'] ?? 0);

        $this->audit->logPlatform(
            AuditAction::WEBHOOK_PAYMENT_SYNCED,
            'subscription',
            (string) $subscriptionId,
            [
                'actorType' => 'system',
                'severity' => 'info',
                'metadata' => [
                    'plan_key' => $metadata['plan_key'] ?? null,
                    'company_id' => $companyId,
                    'action' => $result->reason,
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: $result->reason);
    }

    private function handlePaymentSucceeded(array $intent): WebhookHandlingResult
    {
        $intentId = $intent['id'];
        $metadata = $intent['metadata'] ?? [];
        $stripeCustomerId = $intent['customer'] ?? null;

        $companyId = $this->resolveCompanyId($metadata, $stripeCustomerId);
        if (! $companyId) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve company.');
        }

        // ADR-258: Batch pay (SEPA async confirmation via webhook)
        if (($metadata['type'] ?? '') === 'invoice_batch_pay') {
            return $this->handleBatchPaySucceeded($intent, $metadata, $companyId);
        }

        $invoice = $this->resolveInvoice($metadata, $companyId);
        if (! $invoice) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve finalized invoice.');
        }

        $amount = $intent['amount_received'] ?? $intent['amount'];
        $currency = strtoupper($intent['currency'] ?? config('billing.default_currency', 'EUR'));
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

        // ADR-258: Batch pay failure (SEPA rejection) — invoices unchanged, dunning handles it
        if (($metadata['type'] ?? '') === 'invoice_batch_pay') {
            return $this->handleBatchPayFailed($intent, $metadata, $companyId);
        }

        $invoice = $this->resolveInvoice($metadata, $companyId);
        if (! $invoice) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve finalized invoice.');
        }

        $amount = $intent['amount'] ?? 0;
        $currency = strtoupper($intent['currency'] ?? config('billing.default_currency', 'EUR'));
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

    /**
     * Handle charge.dispute.created — log the dispute, notify admin, do NOT change subscription state.
     */
    private function handleDisputeCreated(array $dispute): WebhookHandlingResult
    {
        $chargeId = $dispute['charge'] ?? null;
        $paymentIntentId = $dispute['payment_intent'] ?? null;
        $amount = $dispute['amount'] ?? 0;
        $currency = strtoupper($dispute['currency'] ?? config('billing.default_currency', 'EUR'));
        $reason = $dispute['reason'] ?? 'unknown';
        $disputeId = $dispute['id'] ?? null;

        // Resolve company from the payment linked to this charge
        $payment = null;
        $companyId = null;

        if ($paymentIntentId) {
            $payment = Payment::where('provider_payment_id', $paymentIntentId)->first();
            $companyId = $payment?->company_id;
        }

        if (! $companyId && $chargeId) {
            $payment = Payment::where('provider_payment_id', $chargeId)->first();
            $companyId = $payment?->company_id;
        }

        Log::channel('billing')->warning('Stripe dispute created', [
            'dispute_id' => $disputeId,
            'charge_id' => $chargeId,
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
            'company_id' => $companyId,
        ]);

        // Audit log for platform admins
        $this->audit->logPlatform(
            AuditAction::WEBHOOK_DISPUTE_CREATED,
            'payment',
            (string) ($payment?->id ?? $paymentIntentId ?? $chargeId),
            [
                'severity' => 'critical',
                'metadata' => [
                    'dispute_id' => $disputeId,
                    'charge_id' => $chargeId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'reason' => $reason,
                    'company_id' => $companyId,
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: 'dispute_created');
    }

    private function handleSetupIntentSucceeded(array $setupIntent): WebhookHandlingResult
    {
        $paymentMethodId = $setupIntent['payment_method'] ?? null;
        $metadata = $setupIntent['metadata'] ?? [];
        $stripeCustomerId = $setupIntent['customer'] ?? null;

        if (! $paymentMethodId) {
            return new WebhookHandlingResult(handled: false, error: 'Missing payment_method on setup_intent.');
        }

        $companyId = $this->resolveCompanyId($metadata, $stripeCustomerId);

        if (! $companyId) {
            return new WebhookHandlingResult(handled: false, error: 'Cannot resolve company.');
        }

        // Retrieve payment method details from Stripe
        $adapter = app(StripePaymentAdapter::class);
        $pm = $adapter->retrievePaymentMethod($paymentMethodId);

        $type = $pm->type ?? 'card';

        if ($type === 'sepa_debit') {
            $sepa = $pm->sepa_debit;
            $profileMetadata = [
                'type' => 'sepa_debit',
                'bank_code' => $sepa?->bank_code ?? null,
                'country' => $sepa?->country ?? null,
                'last4' => $sepa?->last4 ?? '****',
                'holder_name' => $pm->billing_details?->name ?? null,
                'mandate_reference' => $setupIntent['mandate'] ?? null,
                'mandate_status' => 'active',
            ];
            $methodKey = 'sepa_debit';
            $label = 'SEPA •••• ' . ($sepa?->last4 ?? '****');
        } else {
            $card = $pm->card ?? null;
            $profileMetadata = [
                'brand' => $card?->brand ?? 'unknown',
                'last4' => $card?->last4 ?? '****',
                'exp_month' => $card?->exp_month,
                'exp_year' => $card?->exp_year,
                'fingerprint' => $card?->fingerprint,
                'country' => $card?->country,
                'funding' => $card?->funding,
            ];
            $methodKey = 'card';
            $label = ucfirst($profileMetadata['brand']) . ' •••• ' . $profileMetadata['last4'];
        }

        // Unset previous default
        CompanyPaymentProfile::where('company_id', $companyId)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Upsert the new payment profile
        CompanyPaymentProfile::updateOrCreate(
            [
                'company_id' => $companyId,
                'provider_key' => 'stripe',
                'provider_payment_method_id' => $paymentMethodId,
            ],
            [
                'method_key' => $methodKey,
                'label' => $label,
                'is_default' => true,
                'metadata' => $profileMetadata,
            ],
        );

        // Set as default on Stripe customer
        if ($stripeCustomerId) {
            $adapter->setDefaultPaymentMethod($stripeCustomerId, $paymentMethodId);
        }

        $this->audit->logPlatform(
            AuditAction::WEBHOOK_SETUP_INTENT_SYNCED,
            'payment_profile',
            (string) $companyId,
            [
                'actorType' => 'system',
                'severity' => 'info',
                'metadata' => [
                    'company_id' => $companyId,
                    'payment_method_id' => $paymentMethodId,
                    'method_key' => $methodKey,
                    'last4' => $profileMetadata['last4'],
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: 'setup_intent_synced');
    }

    // ── ADR-258: Batch pay webhook handlers ─────────────

    private function handleBatchPaySucceeded(array $intent, array $metadata, int $companyId): WebhookHandlingResult
    {
        $company = Company::find($companyId);
        if (! $company) {
            return new WebhookHandlingResult(handled: false, error: 'Company not found.');
        }

        try {
            $result = InvoiceBatchPayService::confirmPayment(
                company: $company,
                paymentIntentId: $intent['id'],
            );

            $this->audit->logPlatform(
                AuditAction::WEBHOOK_PAYMENT_SYNCED,
                'payment',
                $intent['id'],
                [
                    'actorType' => 'system',
                    'severity' => 'info',
                    'metadata' => [
                        'type' => 'invoice_batch_pay',
                        'paid_invoice_ids' => $result['paid_invoice_ids'] ?? [],
                        'total_paid' => $result['total_paid'] ?? 0,
                    ],
                ],
            );

            return new WebhookHandlingResult(handled: true, action: 'batch_payment_synced');
        } catch (\Throwable $e) {
            Log::channel('billing')->error('[billing] batch pay webhook processing failed', [
                'payment_intent_id' => $intent['id'],
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return new WebhookHandlingResult(handled: false, error: $e->getMessage());
        }
    }

    private function handleBatchPayFailed(array $intent, array $metadata, int $companyId): WebhookHandlingResult
    {
        $invoiceIds = array_map('intval', explode(',', $metadata['invoice_ids'] ?? ''));

        Log::channel('billing')->warning('[billing] batch payment failed (async method rejected)', [
            'company_id' => $companyId,
            'payment_intent_id' => $intent['id'],
            'invoice_ids' => $invoiceIds,
            'failure_code' => $intent['last_payment_error']['code'] ?? null,
        ]);

        $this->audit->logPlatform(
            AuditAction::WEBHOOK_PAYMENT_FAILED,
            'payment',
            $intent['id'],
            [
                'actorType' => 'system',
                'severity' => 'warning',
                'metadata' => [
                    'type' => 'invoice_batch_pay',
                    'company_id' => $companyId,
                    'invoice_ids' => $invoiceIds,
                    'failure_code' => $intent['last_payment_error']['code'] ?? null,
                ],
            ],
        );

        return new WebhookHandlingResult(handled: true, action: 'batch_payment_failed');
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

    // ── ADR-228: Expected confirmation resolution ────────

    private function resolveExpectedConfirmation(string $eventType, array $object): void
    {
        $objectId = $object['id'] ?? null;
        if (! $objectId) {
            return;
        }

        BillingExpectedConfirmation::where('provider_key', 'stripe')
            ->where('expected_event_type', $eventType)
            ->where('provider_reference', $objectId)
            ->where('status', 'pending')
            ->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);
    }
}
