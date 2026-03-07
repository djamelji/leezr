<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Events\ModuleEnabled;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Modules\PlatformModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-224: Creates addon subscription + invoice when a paid module is enabled.
 *
 * Pipeline:
 *   1. Check if module has addon_pricing → skip if not
 *   2. Compute amount (flat/plan_flat pricing)
 *   3. Create/update CompanyAddonSubscription (inside transaction)
 *   4. Create addon invoice (InvoiceIssuer pipeline — wallet-first)
 *   5. If wallet doesn't cover it → invoice stays open for dunning
 */
class AddonBillingListener
{
    public function handle(ModuleEnabled $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            Log::error('[addon-billing] Failed to process addon billing', [
                'company_id' => $event->company->id,
                'module_key' => $event->moduleKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function process(ModuleEnabled $event): void
    {
        $company = $event->company;
        $moduleKey = $event->moduleKey;

        $pm = PlatformModule::where('key', $moduleKey)->first();

        if (! $pm || $pm->addon_pricing === null) {
            return;
        }

        $companyPlanKey = CompanyEntitlements::planKey($company);
        $amount = ModuleQuoteCalculator::computeAmount($pm, $companyPlanKey);

        if ($amount <= 0) {
            return;
        }

        $subscription = Subscription::where('company_id', $company->id)
            ->where('is_current', 1)
            ->first();

        $interval = $subscription?->interval ?? 'monthly';

        // Phase 1: Create/update addon subscription (inside transaction)
        DB::transaction(function () use ($company, $moduleKey, $amount, $interval) {
            CompanyAddonSubscription::updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $moduleKey],
                [
                    'amount_cents' => $amount,
                    'currency' => WalletLedger::ensureWallet($company)->currency,
                    'interval' => $interval,
                    'activated_at' => now(),
                    'deactivated_at' => null,
                ],
            );
        });

        // Phase 2: Create addon invoice (outside transaction — InvoiceIssuer has its own)
        $moduleName = $pm->display_name_override ?? $pm->name;
        $invoice = InvoiceIssuer::createDraft($company, $subscription?->id);

        InvoiceIssuer::addLine(
            $invoice,
            'addon',
            "{$moduleName} addon",
            $amount,
            1,
            moduleKey: $moduleKey,
        );

        $invoice = InvoiceIssuer::finalize($invoice);

        Log::channel('billing')->info('Addon activation invoice created', [
            'company_id' => $company->id,
            'module_key' => $moduleKey,
            'amount' => $amount,
            'invoice_id' => $invoice->id,
        ]);
    }

}
