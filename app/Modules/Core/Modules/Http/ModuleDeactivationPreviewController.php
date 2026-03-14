<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\TaxResolver;
use App\Core\Billing\WalletLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-341: GET /api/modules/{key}/deactivation-preview
 *
 * Returns a complete billing breakdown for addon deactivation:
 *   - Period dates, days used/remaining/total
 *   - Amount paid, consumed, prorated credit
 *   - Tax breakdown (HT, TVA, TTC)
 *   - Wallet balance after credit
 *   - Timing policy (immediate vs end_of_period)
 */
class ModuleDeactivationPreviewController
{
    public function __invoke(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');

        $addon = CompanyAddonSubscription::where('company_id', $company->id)
            ->where('module_key', $key)
            ->whereNull('deactivated_at')
            ->first();

        if (! $addon) {
            return response()->json([
                'has_addon' => false,
            ]);
        }

        $policy = PlatformBillingPolicy::instance();
        $timing = $policy->addon_deactivation_timing ?? 'end_of_period';

        $periodStart = $addon->activated_at;
        $periodEnd = $addon->periodEnd();
        $taxRateBps = TaxResolver::resolveRateBps($company);
        $taxMode = $policy->tax_mode ?? 'exclusive';

        // Period calculation
        $totalDays = $periodStart && $periodEnd ? (int) $periodStart->diffInDays($periodEnd) : 0;
        $daysUsed = $periodStart ? (int) $periodStart->diffInDays(now()) : 0;
        $daysRemaining = max(0, $totalDays - $daysUsed);

        // Financial breakdown (all in cents)
        $amountPaidHt = $addon->amount_cents;
        $amountPaidTax = TaxResolver::compute($amountPaidHt, $taxRateBps);
        $amountPaidTtc = $taxMode === 'inclusive' ? $amountPaidHt : $amountPaidHt + $amountPaidTax;

        $consumedHt = $totalDays > 0 ? (int) round($amountPaidHt * $daysUsed / $totalDays) : $amountPaidHt;
        $consumedTax = TaxResolver::compute($consumedHt, $taxRateBps);
        $consumedTtc = $taxMode === 'inclusive' ? $consumedHt : $consumedHt + $consumedTax;

        // Prorated credit (only for immediate)
        $creditHt = 0;
        $creditTax = 0;
        $creditTtc = 0;
        $activeUntil = $periodEnd?->toDateString();

        if ($timing === 'immediate') {
            $creditHt = $addon->proratedCreditCents();
            $creditTax = TaxResolver::compute($creditHt, $taxRateBps);
            $creditTtc = $taxMode === 'inclusive' ? $creditHt : $creditHt + $creditTax;
            $activeUntil = today()->toDateString();
        }

        // Wallet info
        $walletBalance = WalletLedger::balance($company);

        return response()->json([
            'has_addon' => true,
            'timing' => $timing,
            'module_key' => $key,
            'currency' => $addon->currency,
            'interval' => $addon->interval,

            // Period
            'period_start' => $periodStart?->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
            'active_until' => $activeUntil,
            'total_days' => $totalDays,
            'days_used' => $daysUsed,
            'days_remaining' => $daysRemaining,

            // Amount paid this period
            'amount_paid_ht' => $amountPaidHt,
            'amount_paid_tax' => $amountPaidTax,
            'amount_paid_ttc' => $amountPaidTtc,

            // Consumed (prorata of usage)
            'consumed_ht' => $consumedHt,
            'consumed_tax' => $consumedTax,
            'consumed_ttc' => $consumedTtc,

            // Credit (refund to wallet)
            'credit_ht' => $creditHt,
            'credit_tax' => $creditTax,
            'credit_ttc' => $creditTtc,

            // Tax info
            'tax_rate_bps' => $taxRateBps,
            'tax_mode' => $taxMode,

            // Wallet
            'wallet_balance' => $walletBalance,
            'wallet_balance_after' => $walletBalance + $creditHt,
        ]);
    }
}
