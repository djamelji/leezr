<?php

namespace App\Core\Billing\DTOs;

/**
 * Complete plan change breakdown: proration + next period.
 *
 * Used by planChangePreview() and PlanChangeExecutor.
 */
readonly class PlanChangeBreakdown
{
    public function __construct(
        public string $timing,
        public bool $isUpgrade,
        public bool $isIntervalChange,
        public string $currency,
        public array $fromPlan,
        public array $toPlan,
        public ?PriceBreakdown $immediate,
        public ?array $prorationDetails,
        public PriceBreakdown $nextPeriod,
        public ?CouponInfo $activeCoupon,
        public array $addonLines = [],
    ) {}

    public function toArray(): array
    {
        return [
            'timing' => $this->timing,
            'is_upgrade' => $this->isUpgrade,
            'is_interval_change' => $this->isIntervalChange,
            'currency' => $this->currency,
            'from_plan' => $this->fromPlan,
            'to_plan' => $this->toPlan,
            'immediate' => $this->immediate?->toArray(),
            'proration' => $this->prorationDetails,
            'next_period' => $this->nextPeriod->toArray(),
            'active_coupon' => $this->activeCoupon?->toArray(),
            'addons' => $this->addonLines,
        ];
    }
}
