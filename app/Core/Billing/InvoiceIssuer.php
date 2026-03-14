<?php

namespace App\Core\Billing;

use App\Core\Billing\InvoiceLineDescriptor;
use App\Core\Models\Company;
use App\Modules\Core\Billing\Services\TaxContextResolver;
use App\Notifications\Billing\InvoiceCreated;
use App\Notifications\Billing\PaymentReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\Core\Billing\BillingCoupon;
use App\Modules\Core\Billing\Services\CouponService;

/**
 * Creates and finalizes invoices.
 *
 * Pipeline:
 *   1. Create draft invoice with lines
 *   2. Compute subtotal from lines (invariant: SUM(lines) = subtotal)
 *   3. Apply tax via TaxResolver
 *   4. Compute amount = subtotal + tax_amount
 *   5. Apply wallet credit (if auto_apply_wallet_credit)
 *   6. Compute amount_due = amount - wallet_credit_applied
 *   7. Assign sequential number (finalize)
 *   8. Freeze billing_snapshot
 *
 * Immutability: after finalize, only notes/retry/status/paid_at/voided_at may change.
 */
class InvoiceIssuer
{
    /**
     * Create a draft invoice for a company.
     */
    public static function createDraft(
        Company $company,
        ?int $subscriptionId = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): Invoice {
        $policy = PlatformBillingPolicy::instance();
        $taxContext = TaxContextResolver::resolve($company);

        return Invoice::create([
            'company_id' => $company->id,
            'subscription_id' => $subscriptionId,
            'currency' => WalletLedger::ensureWallet($company)->currency,
            'status' => 'draft',
            'amount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'tax_rate_bps' => $taxContext->taxRateBps,
            'tax_exemption_reason' => $taxContext->exemptionReason,
            'wallet_credit_applied' => 0,
            'amount_due' => 0,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_at' => now()->addDays($policy->invoice_due_days),
        ]);
    }

    /**
     * ADR-328: Create an annexe draft invoice linked to a finalized parent.
     * Does NOT consume the global invoice number sequence.
     *
     * @throws RuntimeException If parent is not finalized
     */
    public static function createAnnexeDraft(
        Invoice $parentInvoice,
        Company $company,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): Invoice {
        if (! $parentInvoice->isFinalized()) {
            throw new RuntimeException('Cannot create annexe for unfinalized parent invoice.');
        }

        $policy = PlatformBillingPolicy::instance();
        $taxContext = TaxContextResolver::resolve($company);
        $suffix = InvoiceNumbering::nextAnnexeSuffix($parentInvoice);

        return Invoice::create([
            'parent_invoice_id' => $parentInvoice->id,
            'annexe_suffix' => $suffix,
            'company_id' => $company->id,
            'subscription_id' => $parentInvoice->subscription_id,
            'currency' => WalletLedger::ensureWallet($company)->currency,
            'status' => 'draft',
            'amount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'tax_rate_bps' => $taxContext->taxRateBps,
            'tax_exemption_reason' => $taxContext->exemptionReason,
            'wallet_credit_applied' => 0,
            'amount_due' => 0,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_at' => now()->addDays($policy->invoice_due_days),
        ]);
    }

    /**
     * Add a line to a draft invoice.
     *
     * @throws RuntimeException If invoice is already finalized
     */
    public static function addLine(
        Invoice $invoice,
        string $type,
        string $description,
        int $unitAmount,
        int $quantity = 1,
        ?string $moduleKey = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?array $metadata = null,
    ): InvoiceLine {
        if ($invoice->isFinalized()) {
            throw new RuntimeException('Cannot add lines to a finalized invoice.');
        }

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'type' => $type,
            'module_key' => $moduleKey,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'amount' => $quantity * $unitAmount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Apply a coupon discount to a draft invoice.
     * Adds a negative line for the discount amount.
     *
     * @throws RuntimeException If invoice is already finalized
     */
    public static function applyCoupon(Invoice $invoice, BillingCoupon $coupon, Company $company): int
    {
        if ($invoice->isFinalized()) {
            throw new RuntimeException('Cannot apply coupon to a finalized invoice.');
        }

        // One coupon per invoice — non-stackable
        if ($invoice->coupon_id) {
            throw new RuntimeException('A coupon is already applied to this invoice.');
        }

        $couponService = app(CouponService::class);

        // Only count positive lines (plan + addons), ignore existing discounts
        $currentSubtotal = (int) $invoice->lines()->where('amount', '>', 0)->sum('amount');
        $discount = $couponService->calculateDiscount($coupon, $currentSubtotal);

        if ($discount <= 0) {
            return 0;
        }

        // Add negative line for the discount
        $desc = InvoiceLineDescriptor::resolve($company->market?->locale ?? 'fr-FR');

        static::addLine(
            $invoice,
            'discount',
            $desc->coupon($coupon->code),
            -$discount,
            1,
            metadata: ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code],
        );

        // Record coupon usage
        $couponService->recordUsage($coupon, $invoice, $company, $discount);

        // Link coupon to invoice
        $invoice->update(['coupon_id' => $coupon->id]);

        return $discount;
    }

    /**
     * Finalize a draft invoice: compute totals, apply wallet, assign number, freeze snapshot.
     *
     * @throws RuntimeException If invoice is already finalized or has no lines
     */
    public static function finalize(Invoice $invoice): Invoice
    {
        if ($invoice->isFinalized()) {
            throw new RuntimeException('Invoice is already finalized.');
        }

        $finalizedInvoice = DB::transaction(function () use ($invoice) {
            // Re-fetch to avoid stale state
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            if ($invoice->isFinalized()) {
                throw new RuntimeException('Invoice is already finalized.');
            }

            $lines = $invoice->lines;

            if ($lines->isEmpty()) {
                throw new RuntimeException('Cannot finalize invoice with no lines.');
            }

            // 1. Compute subtotal from lines
            $subtotal = (int) $lines->sum('amount');

            // 2. Apply tax
            $taxRateBps = $invoice->tax_rate_bps;
            $taxAmount = TaxResolver::compute($subtotal, $taxRateBps);

            // 3. Total (ADR-333 — mode-aware)
            $policy = PlatformBillingPolicy::instance();
            $total = $policy->tax_mode === 'inclusive'
                ? $subtotal
                : $subtotal + $taxAmount;

            // 4. Apply wallet credit (wallet-first)
            $walletCreditApplied = 0;
            $company = $invoice->company;
            $policy = PlatformBillingPolicy::instance();

            if ($policy->auto_apply_wallet_credit && $total > 0) {
                $walletBalance = WalletLedger::balance($company);

                if ($walletBalance > 0) {
                    $walletCreditApplied = min($walletBalance, $total);

                    // ADR-335: Compute FIFO breakdown before debiting (same transaction, same lock)
                    $breakdown = WalletLedger::computeFifoBreakdown($company, $walletCreditApplied);

                    WalletLedger::debit(
                        company: $company,
                        amount: $walletCreditApplied,
                        sourceType: 'invoice_payment',
                        sourceId: $invoice->id,
                        description: "Wallet credit applied to invoice",
                        actorType: 'system',
                        idempotencyKey: "invoice-wallet-{$invoice->id}",
                        metadata: ! empty($breakdown) ? ['sources' => $breakdown] : null,
                    );
                }
            }

            // 5. Amount due
            $amountDue = $total - $walletCreditApplied;

            // 6. Assign number (annexes use parent number + suffix, no global sequence)
            $number = $invoice->isAnnexe()
                ? null  // annexe display number is computed from parent
                : InvoiceNumbering::nextInvoiceNumber();

            // 7. Freeze billing snapshot
            $snapshot = static::buildBillingSnapshot($company);

            // 8. Update invoice
            $now = now();
            $invoice->update([
                'number' => $number,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'amount' => $total,
                'wallet_credit_applied' => $walletCreditApplied,
                'amount_due' => $amountDue,
                'billing_snapshot' => $snapshot,
                'status' => $amountDue <= 0 ? 'paid' : 'open',
                'issued_at' => $now,
                'finalized_at' => $now,
                'paid_at' => $amountDue <= 0 ? $now : null,
            ]);

            $invoice = $invoice->fresh();

            // Ledger: record invoice issued (ADR-142 D3f)
            try {
                LedgerService::recordInvoiceIssued($invoice);
            } catch (\Throwable $e) {
                Log::warning('[ledger] invoice issued recording failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $invoice;
        });

        // ADR-272: Notify company owner about new invoice / payment (outside transaction)
        try {
            $owner = $finalizedInvoice->company?->owner();

            if ($owner) {
                if ($finalizedInvoice->status === 'paid') {
                    $owner->notify(new PaymentReceived($finalizedInvoice));
                } else {
                    $owner->notify(new InvoiceCreated($finalizedInvoice));
                }
            }

            // Prevent cached company relation from leaking to callers
            $finalizedInvoice->unsetRelation('company');
        } catch (\Throwable $e) {
            Log::warning('[billing] Failed to send invoice notification', [
                'invoice_id' => $finalizedInvoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        // ADR-328 S2: Maybe schedule deferred SEPA debit
        if ($finalizedInvoice->amount_due > 0 && $finalizedInvoice->status === 'open') {
            try {
                ScheduledDebitService::maybeSchedule($finalizedInvoice);
            } catch (\Throwable $e) {
                Log::warning('[billing] Failed to schedule debit', [
                    'invoice_id' => $finalizedInvoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $finalizedInvoice;
    }

    /**
     * Build billing snapshot from company data.
     */
    private static function buildBillingSnapshot(Company $company): array
    {
        $market = $company->market;

        $legalStatus = $company->legal_status_key
            ? \App\Core\Markets\LegalStatus::where('key', $company->legal_status_key)
                ->where('market_key', $company->market_key)
                ->first()
            : null;

        // Read billing fields from dynamic field_values
        $fieldCodes = ['legal_name', 'vat_number', 'siret', 'billing_address', 'billing_city', 'billing_postal_code', 'billing_email'];
        $fieldValues = \App\Core\Fields\FieldValue::where('model_type', 'company')
            ->where('model_id', $company->id)
            ->whereHas('definition', fn ($q) => $q->whereIn('code', $fieldCodes))
            ->with('definition:id,code')
            ->get()
            ->pluck('value', 'definition.code')
            ->toArray();

        $address = implode(', ', array_filter([
            $fieldValues['billing_address'] ?? null,
            implode(' ', array_filter([$fieldValues['billing_postal_code'] ?? null, $fieldValues['billing_city'] ?? null])),
        ]));

        return [
            'company_name' => $company->name,
            'company_legal_name' => $fieldValues['legal_name'] ?? $company->name,
            'market_key' => $company->market_key,
            'market_name' => $market?->name,
            'market_locale' => $market?->locale ?? 'fr-FR',
            'currency' => $market?->currency ?? 'EUR',
            'legal_status_key' => $company->legal_status_key ?? null,
            'legal_status_name' => $legalStatus?->name,
            'is_vat_applicable' => $legalStatus?->is_vat_applicable ?? false,
            'vat_rate' => $legalStatus?->vat_rate,
            'vat_number' => $fieldValues['vat_number'] ?? null,
            'siret' => $fieldValues['siret'] ?? null,
            'billing_address' => $address ?: null,
            'billing_email' => $fieldValues['billing_email'] ?? null,
        ];
    }
}
