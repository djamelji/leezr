<?php

namespace App\Core\Billing\DTOs;

/**
 * A single line in a PriceBreakdown.
 *
 * Types: 'plan', 'addon', 'discount', 'proration'
 * Negative unitAmount = credit/discount.
 */
readonly class PriceLine
{
    public int $amount;

    public function __construct(
        public string $type,
        public string $description,
        public int $unitAmount,
        public int $quantity = 1,
        public ?string $moduleKey = null,
        public ?array $metadata = null,
    ) {
        $this->amount = $this->quantity * $this->unitAmount;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'description' => $this->description,
            'unitAmount' => $this->unitAmount,
            'quantity' => $this->quantity,
            'amount' => $this->amount,
            'moduleKey' => $this->moduleKey,
            'metadata' => $this->metadata,
        ];
    }
}
