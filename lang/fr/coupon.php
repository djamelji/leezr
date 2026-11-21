<?php

// ADR-851 — reasons a promotional code is refused (same keys as CouponService::validate errors).
return [
    'coupon_not_found' => "Ce code promo n'existe pas.",
    'coupon_expired' => 'Ce code promo a expiré.',
    'coupon_exhausted' => "Ce code promo a atteint sa limite d'utilisation.",
    'coupon_inactive' => "Ce code promo n'est plus actif.",
    'coupon_not_applicable' => "Ce code promo ne s'applique pas à cette offre.",
    'coupon_usage_limit_per_company' => 'Ce code promo a déjà été utilisé par cette entreprise.',
    'coupon_billing_cycle_mismatch' => "Ce code promo ne s'applique pas à cette périodicité de facturation.",
    'coupon_first_purchase_only' => 'Ce code promo est réservé à une première souscription.',
];
