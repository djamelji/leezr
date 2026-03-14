<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\WalletLedger;
use App\Core\Events\ModuleDisabled;
use Illuminate\Support\Facades\Log;

/**
 * ADR-341: Deactivates addon subscription when a paid module is disabled.
 *
 * Deactivation timing depends on PlatformBillingPolicy:
 *   - 'immediate' → deactivated_at = now() + prorated credit to wallet
 *   - 'end_of_period' → deactivated_at = activated_at + interval (grace period, no credit)
 */
class AddonCreditListener
{
    public function handle(ModuleDisabled $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            Log::error('[addon-credit] Failed to process addon deactivation', [
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
            ->whereNull('deactivated_at')
            ->first();

        if (! $addon) {
            return;
        }

        $timing = PlatformBillingPolicy::instance()->addon_deactivation_timing ?? 'end_of_period';

        if ($timing === 'end_of_period' && $addon->activated_at) {
            $deactivateAt = $addon->periodEnd();

            if ($deactivateAt && $deactivateAt->gt(now())) {
                $addon->update(['deactivated_at' => $deactivateAt]);

                Log::channel('billing')->info('Addon deactivation scheduled (end_of_period)', [
                    'company_id' => $event->company->id,
                    'module_key' => $event->moduleKey,
                    'deactivated_at' => $deactivateAt->toDateString(),
                ]);

                return;
            }
        }

        // Immediate deactivation — credit prorated amount to wallet
        $creditCents = $addon->proratedCreditCents();
        $addon->update(['deactivated_at' => now()]);

        if ($creditCents > 0) {
            WalletLedger::ensureWallet($event->company);
            WalletLedger::credit(
                company: $event->company,
                amount: $creditCents,
                sourceType: 'addon_prorated_credit',
                description: "Prorated credit for {$event->moduleKey} addon deactivation",
                idempotencyKey: "addon-credit-{$event->company->id}-{$event->moduleKey}-" . now()->format('Y-m-d'),
            );

            Log::channel('billing')->info('Addon deactivated immediately with prorated credit', [
                'company_id' => $event->company->id,
                'module_key' => $event->moduleKey,
                'credit_cents' => $creditCents,
            ]);
        } else {
            Log::channel('billing')->info('Addon deactivated immediately (no credit due)', [
                'company_id' => $event->company->id,
                'module_key' => $event->moduleKey,
            ]);
        }
    }
}
