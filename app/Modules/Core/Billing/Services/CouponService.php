<?php

namespace App\Modules\Core\Billing\Services;

use App\Core\Models\Company;
use App\Core\Billing\BillingCoupon;
use App\Core\Billing\BillingCouponUsage;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;

class CouponService
{
    /**
     * Validate a coupon code for a company and plan.
     *
     * @return array{valid: bool, coupon: ?BillingCoupon, error: ?string, discount_preview: ?int}
     */
    public function validate(string $code, Company $company, string $planKey, ?string $billingInterval = null, int $subtotalCents = 0): array
    {
        $coupon = BillingCoupon::where('code', strtoupper(trim($code)))->first();

        if (! $coupon) {
            return ['valid' => false, 'coupon' => null, 'error' => 'coupon_not_found', 'discount_preview' => null];
        }

        if (! $coupon->isUsable()) {
            $reason = $coupon->isExpired() ? 'coupon_expired' : ($coupon->isExhausted() ? 'coupon_exhausted' : 'coupon_inactive');

            return ['valid' => false, 'coupon' => $coupon, 'error' => $reason, 'discount_preview' => null];
        }

        // Check applicable plans
        if ($coupon->applicable_plan_keys && ! in_array($planKey, $coupon->applicable_plan_keys)) {
            return ['valid' => false, 'coupon' => $coupon, 'error' => 'coupon_not_applicable', 'discount_preview' => null];
        }

        // Check per-company usage limit
        $companyUsageCount = BillingCouponUsage::where('coupon_id', $coupon->id)
            ->where('company_id', $company->id)
            ->count();

        $maxPerCompany = $coupon->max_uses_per_company ?? 1;

        if ($companyUsageCount >= $maxPerCompany) {
            return ['valid' => false, 'coupon' => $coupon, 'error' => 'coupon_usage_limit_per_company', 'discount_preview' => null];
        }

        // Check applicable billing cycles
        if ($coupon->applicable_billing_cycles && ! in_array($billingInterval, $coupon->applicable_billing_cycles)) {
            return ['valid' => false, 'coupon' => $coupon, 'error' => 'coupon_billing_cycle_mismatch', 'discount_preview' => null];
        }

        // Check first purchase only restriction
        if ($coupon->first_purchase_only && Subscription::where('company_id', $company->id)->exists()) {
            return ['valid' => false, 'coupon' => $coupon, 'error' => 'coupon_first_purchase_only', 'discount_preview' => null];
        }

        $discountPreview = $this->calculateDiscount($coupon, $subtotalCents);

        return ['valid' => true, 'coupon' => $coupon, 'error' => null, 'discount_preview' => $discountPreview];
    }

    /**
     * Apply a coupon to an invoice. Records usage and increments used_count.
     *
     * @return int discount amount in cents
     */
    public function apply(BillingCoupon $coupon, Invoice $invoice, Company $company): int
    {
        $discount = $this->calculateDiscount($coupon, $invoice->subtotal);

        if ($discount <= 0) {
            return 0;
        }

        BillingCouponUsage::create([
            'coupon_id' => $coupon->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'applied_at' => now(),
            'discount_amount' => $discount,
        ]);

        $coupon->increment('used_count');

        return $discount;
    }

    /**
     * Record coupon usage without recalculating discount.
     * Used by InvoiceIssuer::applyCoupon() which already calculated the discount.
     */
    public function recordUsage(BillingCoupon $coupon, Invoice $invoice, Company $company, int $discount): void
    {
        BillingCouponUsage::create([
            'coupon_id' => $coupon->id,
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'applied_at' => now(),
            'discount_amount' => $discount,
        ]);

        $coupon->increment('used_count');
    }

    /**
     * Calculate the discount amount for a coupon.
     */
    public function calculateDiscount(BillingCoupon $coupon, int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        if ($coupon->type === 'percentage') {
            // value is in basis points (e.g., 2000 = 20%), capped at subtotal
            $discount = (int) round($subtotalCents * min($coupon->value, 10000) / 10000);

            return min($discount, $subtotalCents);
        }

        // fixed_amount: value is in cents, capped at subtotal
        return min($coupon->value, $subtotalCents);
    }
}
