<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\TaxResolver;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ModuleQuoteController
{
    /**
     * GET /api/modules/quote?keys[]=m1&keys[]=m2
     *
     * Returns a read-only pricing quote for the selected modules.
     * Requires auth + company context.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string'],
        ]);

        try {
            $quote = ModuleQuoteCalculator::quoteForCompany($company, $validated['keys']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        // ADR-340: Enrich quote with tax info for UX coherence
        $policy = PlatformBillingPolicy::instance();
        $taxRateBps = TaxResolver::resolveRateBps($company);
        $subtotal = $quote->total;
        $taxAmount = TaxResolver::compute($subtotal, $taxRateBps);
        $totalTtc = $policy->tax_mode === 'inclusive' ? $subtotal : $subtotal + $taxAmount;

        // ADR-341: Check if modules are already invoiced or in grace period
        $alreadyInvoiced = false;
        $gracePeriod = false;
        foreach ($validated['keys'] as $key) {
            $addon = CompanyAddonSubscription::where('company_id', $company->id)
                ->where('module_key', $key)
                ->first();

            if ($addon && $addon->deactivated_at && $addon->deactivated_at->gt(now())) {
                $gracePeriod = true;
            }

            $invoiced = Invoice::whereHas('lines', fn ($q) => $q->where('module_key', $key))
                ->where('company_id', $company->id)
                ->where('period_start', '<=', now())
                ->where('period_end', '>=', now())
                ->whereNotIn('status', ['void'])
                ->exists();

            if ($invoiced) {
                $alreadyInvoiced = true;
            }
        }

        return response()->json(array_merge($quote->toArray(), [
            'subtotal' => $subtotal,
            'tax_rate_bps' => $taxRateBps,
            'tax_amount' => $taxAmount,
            'total_ttc' => $totalTtc,
            'tax_mode' => $policy->tax_mode ?? 'exclusive',
            'already_invoiced' => $alreadyInvoiced,
            'grace_period' => $gracePeriod,
        ]));
    }
}
