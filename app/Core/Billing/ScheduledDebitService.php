<?php

namespace App\Core\Billing;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ADR-328 S2: Determines whether an invoice should be scheduled for deferred SEPA debit
 * rather than immediate payment collection.
 *
 * Rules:
 *   - Only SEPA profiles with a preferred_debit_day trigger scheduling
 *   - Card payments are always immediate (no scheduling)
 *   - Default behavior (no preferred_debit_day) = immediate payment
 */
class ScheduledDebitService
{
    /**
     * If the company's default payment method is SEPA with a preferred_debit_day,
     * create a ScheduledDebit for deferred collection. Otherwise return null
     * (caller should collect immediately).
     */
    public static function maybeSchedule(Invoice $invoice): ?ScheduledDebit
    {
        $defaultPm = CompanyPaymentProfile::where('company_id', $invoice->company_id)
            ->where('is_default', true)
            ->first();

        if (! $defaultPm || $defaultPm->method_key !== 'sepa_debit' || ! $defaultPm->preferred_debit_day) {
            return null;
        }

        $debitDate = static::computeNextDebitDate($defaultPm->preferred_debit_day);

        $scheduled = ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_profile_id' => $defaultPm->id,
            'amount' => $invoice->amount_due,
            'currency' => $invoice->currency,
            'debit_date' => $debitDate,
            'status' => 'pending',
        ]);

        Log::channel('billing')->info('SEPA debit scheduled', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'debit_date' => $debitDate->toDateString(),
            'amount' => $invoice->amount_due,
        ]);

        return $scheduled;
    }

    /**
     * Compute the next debit date from today given a preferred day of month.
     * If the preferred day has already passed this month, schedule for next month.
     */
    public static function computeNextDebitDate(int $preferredDay): Carbon
    {
        $today = now()->startOfDay();

        // Clamp to max days in current month
        $daysInMonth = $today->daysInMonth;
        $day = min($preferredDay, $daysInMonth);
        $candidate = $today->copy()->day($day);

        if ($candidate->lte($today)) {
            // Already passed — schedule for next month
            $nextMonth = $today->copy()->addMonthNoOverflow();
            $daysInNextMonth = $nextMonth->daysInMonth;
            $day = min($preferredDay, $daysInNextMonth);
            $candidate = $nextMonth->day($day);
        }

        return $candidate;
    }
}
