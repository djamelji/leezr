<?php

namespace App\Modules\Core\Billing\Services;

use App\Core\Billing\TaxResolver;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Modules\Core\Billing\DTOs\TaxContext;

/**
 * Resolves the full tax context for a company (ADR-310).
 *
 * Handles 5 cases:
 *   1. Same country (seller=FR, buyer=FR) → standard rate, no exemption
 *   2. B2B intra-EU with valid VAT → rate=0, reverse_charge_intra_eu
 *   3. B2C intra-EU (no VAT or invalid VAT) → standard rate, no exemption
 *   4. Extra-EU → rate=0, export_extra_eu
 *   5. VIES unavailable → fallback to valid (assume reverse charge applies)
 */
class TaxContextResolver
{
    /**
     * Resolve the tax context for a company.
     */
    public static function resolve(Company $company): TaxContext
    {
        $sellerMarketKey = config('billing.platform.market_key', 'FR');
        $sellerMarket = Market::where('key', $sellerMarketKey)->first();

        // No buyer market → fallback to standard rate (domestic treatment)
        if (! $company->market_key) {
            return new TaxContext(
                taxRateBps: TaxResolver::resolveRateBps($company),
                exemptionReason: null,
                buyerIsEu: false,
                sellerIsEu: $sellerMarket?->is_eu ?? false,
                buyerVatNumber: null,
            );
        }

        $buyerMarket = Market::where('key', $company->market_key)->first();

        $sellerIsEu = $sellerMarket?->is_eu ?? false;
        $buyerIsEu = $buyerMarket?->is_eu ?? false;
        $buyerVatNumber = static::resolveVatNumber($company);

        // Case 1: Same country → standard rate
        if ($company->market_key === $sellerMarketKey) {
            return new TaxContext(
                taxRateBps: TaxResolver::resolveRateBps($company),
                exemptionReason: null,
                buyerIsEu: $buyerIsEu,
                sellerIsEu: $sellerIsEu,
                buyerVatNumber: $buyerVatNumber,
            );
        }

        // Case 4: Extra-EU buyer → export exemption
        if (! $buyerIsEu) {
            return new TaxContext(
                taxRateBps: 0,
                exemptionReason: 'export_extra_eu',
                buyerIsEu: false,
                sellerIsEu: $sellerIsEu,
                buyerVatNumber: $buyerVatNumber,
            );
        }

        // Cases 2 & 3: Intra-EU (different countries, both EU)
        if ($sellerIsEu && $buyerIsEu && $buyerVatNumber) {
            // B2B intra-EU: validate VAT via VIES
            $countryCode = $company->market_key;
            $validation = VatValidationService::validate($buyerVatNumber, $countryCode);

            if ($validation['valid']) {
                // Case 2: Valid VAT → reverse charge
                return new TaxContext(
                    taxRateBps: 0,
                    exemptionReason: 'reverse_charge_intra_eu',
                    buyerIsEu: true,
                    sellerIsEu: true,
                    buyerVatNumber: $buyerVatNumber,
                );
            }
        }

        // Case 3: B2C intra-EU (no VAT or invalid VAT) → standard rate
        return new TaxContext(
            taxRateBps: TaxResolver::resolveRateBps($company),
            exemptionReason: null,
            buyerIsEu: $buyerIsEu,
            sellerIsEu: $sellerIsEu,
            buyerVatNumber: $buyerVatNumber,
        );
    }

    /**
     * Read the company's VAT number from dynamic field_values.
     */
    private static function resolveVatNumber(Company $company): ?string
    {
        $value = FieldValue::where('model_type', 'company')
            ->where('model_id', $company->id)
            ->whereHas('definition', fn ($q) => $q->where('code', 'vat_number'))
            ->value('value');

        return $value ?: null;
    }
}
