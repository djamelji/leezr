<?php

namespace App\Core\Billing\DTOs;

/**
 * ADR-632 (M2) — ligne de facture CANDIDATE (simulation en mémoire, jamais persistée).
 * Bornes de service semi-ouvertes `[start, end)`. `taxAmountCents` est INFORMATIF (par ligne) ;
 * la TVA faisant foi est l'agrégat calculé sur le sous-total total (cf. BillingRunSimulation),
 * cohérent avec `InvoiceIssuer::finalize`.
 */
readonly class CandidateInvoiceLine
{
    public int $ttcCents;

    public function __construct(
        public string $type,                 // plan | addon | module | adjustment | discount
        public ?string $moduleKey,
        public string $label,
        public ?string $servicePeriodStart,  // ISO ou null
        public ?string $servicePeriodEnd,    // exclusif
        public int $priceSnapshotCents,
        public string $currency,
        public int $quantity,
        public ?int $prorationNum,
        public ?int $prorationDen,
        public int $amountHtCents,
        public int $taxAmountCents,           // informatif (par ligne)
        public ?int $sourceEventId = null,
        public bool $excludedAmbiguous = false,
    ) {
        $this->ttcCents = $this->amountHtCents + $this->taxAmountCents;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'module_key' => $this->moduleKey,
            'label' => $this->label,
            'service_period_start' => $this->servicePeriodStart,
            'service_period_end' => $this->servicePeriodEnd,
            'price_snapshot_cents' => $this->priceSnapshotCents,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'proration_num' => $this->prorationNum,
            'proration_den' => $this->prorationDen,
            'amount_ht_cents' => $this->amountHtCents,
            'tax_amount_cents' => $this->taxAmountCents,
            'ttc_cents' => $this->ttcCents,
            'source_event_id' => $this->sourceEventId,
            'excluded_ambiguous' => $this->excludedAmbiguous,
        ];
    }
}
