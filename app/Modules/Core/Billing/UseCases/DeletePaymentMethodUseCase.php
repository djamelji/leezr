<?php

namespace App\Modules\Core\Billing\UseCases;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * ADR-362: Delete a payment method — extracted from CompanyPaymentMethodController.
 *
 * Guards:
 *   - Profile must exist and belong to company
 *   - At least one payment method must remain
 * Side effects:
 *   - Stripe detach (best-effort, non-blocking)
 *   - If deleted was default, promote next card
 */
class DeletePaymentMethodUseCase
{
    /**
     * @return array{success: bool, message: string, code: int}
     */
    public static function execute(Company $company, int $profileId): array
    {
        $profile = CompanyPaymentProfile::where('id', $profileId)
            ->where('company_id', $company->id)
            ->first();

        if (! $profile) {
            return ['success' => false, 'message' => 'Card not found.', 'code' => 404];
        }

        $totalMethods = CompanyPaymentProfile::where('company_id', $company->id)->count();
        if ($totalMethods <= 1) {
            return ['success' => false, 'message' => 'You must keep at least one payment method.', 'code' => 422];
        }

        // Stripe detach best-effort
        if ($profile->provider_payment_method_id) {
            try {
                $adapter = app(StripePaymentAdapter::class);
                $adapter->detachPaymentMethod($profile->provider_payment_method_id);
            } catch (\Throwable $e) {
                Log::warning('[billing] Stripe detach failed', [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $wasDefault = $profile->is_default;
        $profile->delete();

        // Promote next card if deleted was default
        if ($wasDefault) {
            CompanyPaymentProfile::where('company_id', $company->id)
                ->orderBy('id')
                ->first()
                ?->update(['is_default' => true]);
        }

        return ['success' => true, 'message' => 'Card removed.', 'code' => 200];
    }
}
