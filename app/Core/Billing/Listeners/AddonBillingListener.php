<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\InvoiceLineDescriptor;
use App\Core\Billing\Subscription;
use App\Core\Billing\InvoiceAutoChargeService;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\WalletLedger;
use App\Core\Events\ModuleEnabled;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Modules\PlatformModule;
use App\Core\Notifications\NotificationDispatcher;
use App\Notifications\Billing\AddonActivated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-224: Creates addon subscription + invoice when a paid module is enabled.
 * ADR-328 S5: Trial guard — subscription tracked but no invoice during trial.
 * ADR-328 S1: Addon invoice = annexe of main subscription invoice when possible.
 *
 * Pipeline:
 *   1. Check if module has addon_pricing → skip if not
 *   2. Compute amount (flat/plan_flat pricing)
 *   3. Create/update CompanyAddonSubscription (inside transaction)
 *   4. ADR-328 S5: If trialing → stop here (first post-trial renewal includes addon lines)
 *   5. ADR-328 S1: If main invoice exists for current period → create annexe
 *      Otherwise create standalone invoice (pre-renewal case)
 *   6. If wallet doesn't cover it → invoice stays open for dunning
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

        $interval = $subscription?->interval ?? (PlatformBillingPolicy::instance()->default_billing_interval ?? 'monthly');

        // ADR-341: Grace period guard — if addon has future deactivated_at, just reactivate (clear deactivated_at)
        $existingAddon = CompanyAddonSubscription::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->first();

        if ($existingAddon && $existingAddon->deactivated_at && $existingAddon->deactivated_at->gt(now())) {
            $existingAddon->update(['deactivated_at' => null]);
            Log::channel('billing')->info('Addon reactivated during grace period — no new invoice', [
                'company_id' => $company->id,
                'module_key' => $moduleKey,
            ]);

            return;
        }

        // Phase 1: Create/update addon subscription (inside transaction)
        DB::transaction(function () use ($company, $moduleKey, $amount, $interval, $existingAddon) {
            $data = [
                'amount_cents' => $amount,
                'currency' => WalletLedger::ensureWallet($company)->currency,
                'interval' => $interval,
                'deactivated_at' => null,
            ];

            // ADR-341: Preserve original activated_at on reactivation
            if (! $existingAddon || $existingAddon->deactivated_at !== null) {
                $data['activated_at'] = now();
            }

            CompanyAddonSubscription::updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $moduleKey],
                $data,
            );
        });

        // ADR-328 S5: Trial guard — track addon but defer billing to first post-trial renewal
        if ($subscription && $subscription->isTrialing()) {
            Log::channel('billing')->info('Addon activated during trial — no invoice', [
                'company_id' => $company->id,
                'module_key' => $moduleKey,
                'amount' => $amount,
            ]);

            return;
        }

        // ADR-340: Idempotency guard — skip if addon already invoiced for current period
        $existingAddonInvoice = Invoice::whereHas('lines', fn ($q) => $q->where('module_key', $moduleKey))
            ->where('company_id', $company->id)
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->whereNotIn('status', ['void'])
            ->first();

        if ($existingAddonInvoice) {
            Log::channel('billing')->info('Addon already invoiced for current period — skipping', [
                'company_id' => $company->id,
                'module_key' => $moduleKey,
                'existing_invoice_id' => $existingAddonInvoice->id,
            ]);

            return;
        }

        // Phase 2: Create addon invoice (outside transaction — InvoiceIssuer has its own)
        // ADR-328 S1: Prefer annexe of the current period's main invoice
        $moduleName = $pm->display_name_override ?? $pm->name;
        $periodStart = now()->toDateString();
        $periodEnd = ($interval === 'yearly' ? now()->addYear() : now()->addMonth())->toDateString();

        $mainInvoice = $subscription
            ? Invoice::where('subscription_id', $subscription->id)
                ->whereNull('parent_invoice_id')
                ->whereNotNull('finalized_at')
                ->where('period_start', '<=', now())
                ->where('period_end', '>=', now())
                ->latest('finalized_at')
                ->first()
            : null;

        $invoice = $mainInvoice
            ? InvoiceIssuer::createAnnexeDraft($mainInvoice, $company, $periodStart, $periodEnd)
            : InvoiceIssuer::createDraft($company, $subscription?->id, $periodStart, $periodEnd);

        $desc = InvoiceLineDescriptor::resolve($company->market?->locale ?? 'fr-FR');

        InvoiceIssuer::addLine(
            $invoice,
            'addon',
            $desc->addon($moduleName),
            $amount,
            1,
            moduleKey: $moduleKey,
        );

        $invoice = InvoiceIssuer::finalize($invoice);

        // ADR-333: Auto-charge addon invoice (OUTSIDE transaction)
        InvoiceAutoChargeService::attempt($invoice, $subscription);

        Log::channel('billing')->info('Addon activation invoice created', [
            'company_id' => $company->id,
            'module_key' => $moduleKey,
            'amount' => $amount,
            'invoice_id' => $invoice->id,
        ]);

        // ADR-272: Notify company owner about addon activation
        try {
            $owner = $company->owner();

            if ($owner) {
                NotificationDispatcher::send(
                    topicKey: 'billing.addon_activated',
                    recipients: [$owner],
                    payload: ['module_name' => $moduleName, 'invoice_id' => $invoice->id, 'amount' => $invoice->formatted_total],
                    company: $company,
                    mailNotification: new AddonActivated($moduleName, $invoice),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[addon-billing] Failed to send addon activation notification', [
                'company_id' => $company->id,
                'module_key' => $moduleKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
