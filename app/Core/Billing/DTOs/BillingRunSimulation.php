<?php

namespace App\Core\Billing\DTOs;

/**
 * ADR-632 (M2) — résultat déterministe d'une simulation de facture consolidée (shadow mode).
 * AUCUNE persistance. `eligibleForEmission` est TOUJOURS false en M2 (simulation) ; `holds` et
 * `warnings` expliquent pourquoi (shadow, prix provisional, watermark billed_through indisponible,
 * période ambiguë…). La TVA faisant foi est `taxAmountCents` (agrégat sur `subtotalHtCents`).
 */
readonly class BillingRunSimulation
{
    public function __construct(
        public string $runIdentity,
        public int $companyId,
        public ?int $subscriptionId,
        public string $currency,
        public ?string $periodStart,
        public ?string $periodEnd,            // exclusif ; null si borne ambiguë
        public array $lines,                   // CandidateInvoiceLine[]
        public int $subtotalHtCents,
        public int $taxRateBps,
        public int $taxAmountCents,            // agrégat (fait foi)
        public int $totalTtcCents,
        public bool $eligibleForEmission,
        public array $holds,                   // ex. cycle_hold, pricing_review_required
        public array $warnings,                // ex. billed_through_unavailable, ambiguous_period_event:#, period_overlap:module
        public int $eventsRead,
    ) {}

    public function toArray(): array
    {
        return [
            'run_identity' => $this->runIdentity,
            'company_id' => $this->companyId,
            'subscription_id' => $this->subscriptionId,
            'currency' => $this->currency,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'lines' => array_map(fn (CandidateInvoiceLine $l) => $l->toArray(), $this->lines),
            'subtotal_ht_cents' => $this->subtotalHtCents,
            'tax_rate_bps' => $this->taxRateBps,
            'tax_amount_cents' => $this->taxAmountCents,
            'total_ttc_cents' => $this->totalTtcCents,
            'eligible_for_emission' => $this->eligibleForEmission,
            'holds' => $this->holds,
            'warnings' => $this->warnings,
            'events_read' => $this->eventsRead,
        ];
    }
}
