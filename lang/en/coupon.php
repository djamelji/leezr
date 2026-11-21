<?php

// ADR-851 — reasons a promotional code is refused (same keys as CouponService::validate errors).
return [
    'coupon_not_found' => 'This promo code does not exist.',
    'coupon_expired' => 'This promo code has expired.',
    'coupon_exhausted' => 'This promo code has reached its usage limit.',
    'coupon_inactive' => 'This promo code is no longer active.',
    'coupon_not_applicable' => 'This promo code does not apply to this plan.',
    'coupon_usage_limit_per_company' => 'This promo code was already used by this company.',
    'coupon_billing_cycle_mismatch' => 'This promo code does not apply to this billing cycle.',
    'coupon_first_purchase_only' => 'This promo code is reserved for a first subscription.',
];
