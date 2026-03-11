<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Events\ModuleDisabled;
use Illuminate\Support\Facades\Log;

/**
 * ADR-328 S7: Deactivates addon subscription when a paid module is disabled.
 *
 * No prorated credit — addon stays active until period end, then
 * BillingRenewCommand skips it on next renewal (scope active() filters
 * whereNull('deactivated_at')).
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
            ->active()
            ->first();

        if (! $addon) {
            return;
        }

        $addon->update(['deactivated_at' => now()]);

        Log::channel('billing')->info('Addon deactivated — no prorated credit (ADR-328 S7)', [
            'company_id' => $event->company->id,
            'module_key' => $event->moduleKey,
        ]);
    }
}
