<?php

namespace App\Core\Modules\Pricing;

/**
 * A single billable line in a module quote.
 */
final class QuoteLine
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly int $amount, // cents
        public readonly string $pricingModel,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'amount' => $this->amount,
            'pricing_model' => $this->pricingModel,
        ];
    }
}
