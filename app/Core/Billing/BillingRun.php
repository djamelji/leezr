<?php

namespace App\Core\Billing;

use App\Core\Billing\DTOs\BillingRunSimulation;
use App\Core\Billing\DTOs\CandidateInvoiceLine;
use App\Core\Billing\DTOs\PriceLine;
use App\Core\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-632 (M2) — consolidateur BillingRun en SHADOW MODE : simule une facture consolidée complète
 * (plan + modules/addons + proratas antérieurs + remises + taxes) SANS aucun effet de bord.
 *
 * Réutilise PricingEngine / PriceBreakdown / TaxResolver / PeriodBounds (pas de second moteur de prix).
 * Garantie « zéro effet de bord » : toute la simulation s'exécute dans une transaction SYSTÉMATIQUEMENT
 * annulée (rollback), ce qui neutralise même les écritures incidentes (ex. WalletLedger::ensureWallet
 * appelé par PricingEngine). Aucune réservation d'événement, aucun draft, aucune finalisation, aucune
 * InvoiceLine, aucun ledger, aucun paiement. La réservation/émission réelle = M3/M4.
 */
class BillingRun
{
    public function simulate(Subscription $subscription): BillingRunSimulation
    {
        DB::beginTransaction();
        try {
            return $this->build($subscription);
        } finally {
            DB::rollBack(); // SHADOW : annule tout write incident → aucun effet de bord persistant
        }
    }

    private function build(Subscription $sub): BillingRunSimulation
    {
        $company = Company::findOrFail($sub->company_id);
        $breakdown = PricingEngine::forCurrentPeriod($sub, $company);
        $currency = $breakdown->currency;
        $rateBps = $breakdown->taxRateBps;
        $taxInclusive = PlatformBillingPolicy::instance()->tax_mode === 'inclusive';

        $holds = [];
        $warnings = [];

        // Bornes de période (semi-ouvertes ; anniversaire préservé ; ambigu → null).
        $pb = PeriodBounds::fromLegacy($sub->current_period_start, $sub->current_period_end);
        $periodStart = Carbon::parse($sub->current_period_start)->toIso8601String();
        $periodEnd = $pb->endExclusive?->toIso8601String();
        if (! $pb->isResolved()) {
            $warnings[] = 'ambiguous_current_period'; // libellé de fin inconnu — montants pleins restent valides
        }

        $lines = [];
        $recurringModules = [];

        // Lignes récurrentes (plan + addons + remise) — montants PLEINS (pas de prorata).
        foreach ($breakdown->lines as $pl) {
            /** @var PriceLine $pl */
            if ($pl->moduleKey) {
                $recurringModules[$pl->moduleKey] = [$periodStart, $periodEnd];
            }
            $lines[] = new CandidateInvoiceLine(
                type: $pl->type,
                moduleKey: $pl->moduleKey,
                label: $pl->description,
                servicePeriodStart: $periodStart,
                servicePeriodEnd: $periodEnd,
                priceSnapshotCents: $pl->unitAmount,
                currency: $currency,
                quantity: $pl->quantity,
                prorationNum: null,
                prorationDen: null,
                amountHtCents: $pl->amount,
                taxAmountCents: TaxResolver::compute(max(0, $pl->amount), $rateBps),
            );
        }

        // Événements facturables ÉLIGIBLES (lecture seule — JAMAIS réservés en M2).
        $events = BillableEvent::where('company_id', $company->id)
            ->where('currency', $currency)
            ->where('subscription_id', $sub->id)
            ->where('status', BillableEvent::STATUS_ELIGIBLE)
            ->get();

        foreach ($events as $ev) {
            $evStart = $ev->service_period_start;
            $evEnd = $ev->service_period_end;

            // Période d'événement ambiguë/incomplète → exclue du total, aucun montant fictif.
            if (! $evStart || ! $evEnd || Carbon::parse($evEnd)->lte(Carbon::parse($evStart))) {
                $warnings[] = 'ambiguous_period_event:'.$ev->id;
                $lines[] = $this->ambiguousLine($ev, $currency);

                continue;
            }

            // Détection de chevauchement avec une prestation récurrente du même module (même période).
            if ($ev->module_key && isset($recurringModules[$ev->module_key])) {
                [$rs, $re] = $recurringModules[$ev->module_key];
                if ($re !== null && $this->overlaps($evStart, $evEnd, $rs, $re)) {
                    $warnings[] = 'period_overlap:'.$ev->module_key;
                }
            }

            $lines[] = new CandidateInvoiceLine(
                type: $ev->type === 'module_deactivation' ? 'adjustment' : ($ev->module_key ? 'module' : 'adjustment'),
                moduleKey: $ev->module_key,
                label: ($ev->module_key ?? $ev->type).' — prorata',
                servicePeriodStart: Carbon::parse($evStart)->toIso8601String(),
                servicePeriodEnd: Carbon::parse($evEnd)->toIso8601String(),
                priceSnapshotCents: (int) ($ev->price_snapshot_cents ?? 0),
                currency: $currency,
                quantity: 1,
                prorationNum: $ev->proration_num,
                prorationDen: $ev->proration_den,
                amountHtCents: (int) ($ev->amount_ht_cents ?? 0),
                taxAmountCents: TaxResolver::compute(max(0, (int) ($ev->amount_ht_cents ?? 0)), $rateBps),
                sourceEventId: $ev->id,
            );
        }

        // Sous-total FINANCIER (exclut les lignes ambiguës) + TVA AGRÉGÉE (fait foi, comme finalize).
        $subtotal = array_sum(array_map(
            fn (CandidateInvoiceLine $l) => $l->excludedAmbiguous ? 0 : $l->amountHtCents,
            $lines,
        ));
        $taxAmount = TaxResolver::compute($subtotal, $rateBps);
        $total = $taxInclusive ? $subtotal : $subtotal + $taxAmount;

        // Prix provisional → cycle bloqué (ADR-593) ; jamais finalisable.
        if (! $sub->hasReliableContractedPrice()) {
            $holds[] = 'cycle_hold';
            $holds[] = 'pricing_review_required';
        }

        // M2 = shadow : watermark anti-double-facturation (billed_through) non disponible → pas d'émission.
        $warnings[] = 'billed_through_unavailable';
        $warnings[] = 'shadow_mode';

        $policyVersion = $sub->commercial_policy_version ?? 'v1';
        $identity = sprintf('run:%d:%s:%d:%s:%s', $company->id, $currency, $sub->id, $periodStart, $policyVersion);

        return new BillingRunSimulation(
            runIdentity: $identity,
            companyId: $company->id,
            subscriptionId: $sub->id,
            currency: $currency,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            lines: $lines,
            subtotalHtCents: $subtotal,
            taxRateBps: $rateBps,
            taxAmountCents: $taxAmount,
            totalTtcCents: $total,
            eligibleForEmission: false, // M2 : TOUJOURS une simulation, jamais émissible
            holds: array_values(array_unique($holds)),
            warnings: array_values(array_unique($warnings)),
            eventsRead: $events->count(),
        );
    }

    private function ambiguousLine(BillableEvent $ev, string $currency): CandidateInvoiceLine
    {
        return new CandidateInvoiceLine(
            type: $ev->module_key ? 'module' : 'adjustment',
            moduleKey: $ev->module_key,
            label: ($ev->module_key ?? $ev->type).' — période ambiguë (exclue)',
            servicePeriodStart: optional($ev->service_period_start)->toIso8601String(),
            servicePeriodEnd: optional($ev->service_period_end)->toIso8601String(),
            priceSnapshotCents: (int) ($ev->price_snapshot_cents ?? 0),
            currency: $currency,
            quantity: 1,
            prorationNum: $ev->proration_num,
            prorationDen: $ev->proration_den,
            amountHtCents: 0,          // exclue du total — aucun montant fictif
            taxAmountCents: 0,
            sourceEventId: $ev->id,
            excludedAmbiguous: true,
        );
    }

    private function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return Carbon::parse($aStart)->lt(Carbon::parse($bEnd))
            && Carbon::parse($bStart)->lt(Carbon::parse($aEnd));
    }
}
