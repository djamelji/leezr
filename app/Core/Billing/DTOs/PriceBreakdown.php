<?php

namespace App\Core\Billing\DTOs;

/**
 * Complete price breakdown: lines + subtotal + tax + total.
 *
 * Used by:
 *   - Preview endpoints → toArray() → JSON response
 *   - InvoiceIssuer → toInvoiceLines() → addLine() calls
 */
readonly class PriceBreakdown
{
    public int $subtotal;
    public int $taxAmount;
    public int $total;

    /**
     * @param  PriceLine[]  $lines
     */
    public function __construct(
        public array $lines,
        public int $taxRateBps,
        public ?string $taxExemptionReason,
        public string $currency,
        public ?CouponInfo $coupon = null,
    ) {
        $this->subtotal = array_sum(array_map(fn (PriceLine $l) => $l->amount, $this->lines));
        $this->taxAmount = $this->subtotal > 0
            ? (int) floor($this->subtotal * $this->taxRateBps / 10000)
            : 0;
        $this->total = $this->subtotal + $this->taxAmount;
    }

    /** Positive lines only (plan + addons). */
    public function positiveLines(): array
    {
        return array_values(array_filter($this->lines, fn (PriceLine $l) => $l->amount > 0));
    }

    /** Discount line (coupon) if present. */
    public function discountLine(): ?PriceLine
    {
        foreach ($this->lines as $line) {
            if ($line->type === 'discount') {
                return $line;
            }
        }

        return null;
    }

    /** Plan line if present. */
    public function planLine(): ?PriceLine
    {
        foreach ($this->lines as $line) {
            if ($line->type === 'plan') {
                return $line;
            }
        }

        return null;
    }

    /** Addon lines. */
    public function addonLines(): array
    {
        return array_values(array_filter($this->lines, fn (PriceLine $l) => $l->type === 'addon'));
    }

    /**
     * Convert to InvoiceIssuer-compatible line arrays.
     *
     * @return array[] Each element has: type, description, unitAmount, quantity, moduleKey, metadata
     */
    public function toInvoiceLines(): array
    {
        return array_map(fn (PriceLine $l) => $l->toArray(), $this->lines);
    }

    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (PriceLine $l) => $l->toArray(), $this->lines),
            'subtotal' => $this->subtotal,
            'tax_rate_bps' => $this->taxRateBps,
            'tax_exemption_reason' => $this->taxExemptionReason,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
            'currency' => $this->currency,
            'coupon' => $this->coupon?->toArray(),
        ];
    }
}
