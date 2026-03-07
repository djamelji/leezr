<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CreditNote;
use App\Core\Billing\InvoiceNumbering;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Events\ModuleDisabled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-224: Deactivates addon subscription + creates prorated credit note
 * when a paid module is disabled.
 *
 * Pipeline:
 *   1. Find active addon subscription for this module → skip if none
 *   2. Deactivate (set deactivated_at)
 *   3. Compute prorated credit (remaining days / total days in period)
 *   4. Create CreditNote + credit wallet (inside transaction)
 */
class AddonCreditListener
{
    public function handle(ModuleDisabled $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            Log::error('[addon-credit] Failed to process addon credit', [
                'company_id' => $event->company->id,
                'module_key' => $event->moduleKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function process(ModuleDisabled $event): void
    {
        $addon = CompanyAddonSubscription::where('company_id', $event->company->id)
            ->where('module_key', $event->moduleKey)
            ->active()
            ->first();

        if (! $addon) {
            return;
        }

        $addon->update(['deactivated_at' => now()]);

        $subscription = Subscription::where('company_id', $event->company->id)
            ->where('is_current', 1)
            ->first();

        if (! $subscription || ! $subscription->current_period_end || ! $subscription->current_period_start) {
            return;
        }

        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;
        $totalDays = max(1, $periodStart->diffInDays($periodEnd));
        $remainingDays = max(0, (int) now()->diffInDays($periodEnd, false));
        $creditAmount = (int) round($addon->amount_cents * $remainingDays / $totalDays);

        if ($creditAmount <= 0) {
            return;
        }

        DB::transaction(function () use ($event, $addon, $creditAmount) {
            $number = InvoiceNumbering::nextCreditNoteNumber();

            $creditNote = CreditNote::create([
                'number' => $number,
                'company_id' => $event->company->id,
                'amount' => $creditAmount,
                'currency' => $addon->currency ?? config('billing.default_currency', 'EUR'),
                'reason' => "Addon {$addon->module_key} deactivated — prorated credit",
                'status' => 'applied',
                'issued_at' => now(),
                'applied_at' => now(),
                'metadata' => ['module_key' => $addon->module_key],
            ]);

            $txn = WalletLedger::credit(
                company: $event->company,
                amount: $creditAmount,
                sourceType: 'credit_note',
                sourceId: $creditNote->id,
                description: "Credit for addon {$addon->module_key} deactivation",
                actorType: 'system',
                idempotencyKey: "addon-credit-{$addon->id}-{$addon->deactivated_at->timestamp}",
            );

            $creditNote->update(['wallet_transaction_id' => $txn->id]);

            Log::channel('billing')->info('Addon credit note issued', [
                'company_id' => $event->company->id,
                'module_key' => $addon->module_key,
                'credit_amount' => $creditAmount,
                'credit_note_id' => $creditNote->id,
            ]);
        });
    }
}
