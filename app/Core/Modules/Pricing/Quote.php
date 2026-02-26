<?php

namespace App\Core\Modules\Pricing;

/**
 * Immutable DTO representing a module pricing quote.
 *
 * - total: sum of all billable lines in cents
 * - currency: ISO currency code
 * - lines: billable modules only (explicitly selected, addon-priced)
 * - included: transitively required modules (not billed)
 */
final class Quote
{
    /**
     * @param int $total Total in cents
     * @param string $currency ISO currency code
     * @param QuoteLine[] $lines Billable lines
     * @param QuoteIncluded[] $included Non-billed required modules
     */
    public function __construct(
        public readonly int $total,
        public readonly string $currency,
        public readonly array $lines,
        public readonly array $included,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'currency' => $this->currency,
            'lines' => array_map(fn (QuoteLine $l) => $l->toArray(), $this->lines),
            'included' => array_map(fn (QuoteIncluded $i) => $i->toArray(), $this->included),
        ];
    }
}
