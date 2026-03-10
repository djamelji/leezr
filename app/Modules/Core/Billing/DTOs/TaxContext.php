<?php

namespace App\Modules\Core\Billing\DTOs;

/**
 * Value object holding the resolved tax context for a company (ADR-310).
 *
 * Used by InvoiceIssuer and CompanyBillingReadService to determine
 * the tax rate and exemption reason for invoices and previews.
 */
class TaxContext
{
    public function __construct(
        public readonly int $taxRateBps,
        public readonly ?string $exemptionReason,
        public readonly bool $buyerIsEu,
        public readonly bool $sellerIsEu,
        public readonly ?string $buyerVatNumber,
    ) {}

    public function isExempt(): bool
    {
        return $this->exemptionReason !== null;
    }
}
