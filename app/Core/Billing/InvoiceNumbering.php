<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Sequential invoice/credit-note numbering without gaps.
 *
 * Locking is minimal: SELECT FOR UPDATE on the policy row only for
 * the duration of number assignment, not the full invoice creation.
 *
 * Format: {PREFIX}-{YYYY}-{NNNNNN}
 * Example: INV-2026-000001, CN-2026-000003
 */
class InvoiceNumbering
{
    /**
     * Assign the next invoice number.
     * Must be called inside a DB transaction or will create its own.
     */
    public static function nextInvoiceNumber(): string
    {
        return DB::transaction(function () {
            $policy = PlatformBillingPolicy::query()->lockForUpdate()->first()
                ?? PlatformBillingPolicy::instance();

            // Re-lock after potential creation
            if (!$policy->wasRecentlyCreated) {
                $policy = PlatformBillingPolicy::where('id', $policy->id)->lockForUpdate()->first();
            }

            $prefix = $policy->invoice_prefix ?? 'INV';
            $sequence = $policy->invoice_next_number ?? 1;
            $year = now()->format('Y');

            $number = sprintf('%s-%s-%06d', $prefix, $year, $sequence);

            $policy->update(['invoice_next_number' => $sequence + 1]);

            return $number;
        });
    }

    /**
     * ADR-328: Assign the next annexe suffix for a parent invoice.
     * Does NOT consume the global invoice sequence.
     *
     * @return string A, B, C, ..., Z, AA, AB, ...
     */
    public static function nextAnnexeSuffix(Invoice $parentInvoice): string
    {
        $lastSuffix = Invoice::where('parent_invoice_id', $parentInvoice->id)
            ->orderByDesc('annexe_suffix')
            ->value('annexe_suffix');

        if (! $lastSuffix) {
            return 'A';
        }

        // PHP ++ on strings: A→B, Z→AA, AA→AB
        return ++$lastSuffix;
    }

    /**
     * Assign the next credit note number.
     */
    public static function nextCreditNoteNumber(): string
    {
        return DB::transaction(function () {
            $policy = PlatformBillingPolicy::query()->lockForUpdate()->first()
                ?? PlatformBillingPolicy::instance();

            if (!$policy->wasRecentlyCreated) {
                $policy = PlatformBillingPolicy::where('id', $policy->id)->lockForUpdate()->first();
            }

            $prefix = $policy->credit_note_prefix ?? 'CN';
            $sequence = $policy->credit_note_next_number ?? 1;
            $year = now()->format('Y');

            $number = sprintf('%s-%s-%06d', $prefix, $year, $sequence);

            $policy->update(['credit_note_next_number' => $sequence + 1]);

            return $number;
        });
    }
}
