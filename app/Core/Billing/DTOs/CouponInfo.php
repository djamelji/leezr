<?php

namespace App\Core\Billing\DTOs;

/**
 * Coupon information attached to a PriceBreakdown.
 */
readonly class CouponInfo
{
    public function __construct(
        public int $id,
        public string $code,
        public string $type,
        public int $value,
        public int $discount,
        public ?int $monthsRemaining,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'discount' => $this->discount,
            'months_remaining' => $this->monthsRemaining,
        ];
    }
}
