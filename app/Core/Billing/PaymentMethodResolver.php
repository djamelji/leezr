<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Support\Collection;

/**
 * Resolves ordered payment methods for a company.
 *
 * Priority: default first, then others ordered by id.
 * Used by DunningEngine for fallback payment attempts.
 */
class PaymentMethodResolver
{
    /**
     * @return Collection<CompanyPaymentProfile>
     */
    public static function resolveForCompany(Company $company): Collection
    {
        return CompanyPaymentProfile::where('company_id', $company->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }
}
