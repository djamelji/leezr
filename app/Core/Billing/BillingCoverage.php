<?php

namespace App\Core\Billing;

use Illuminate\Support\Carbon;

/**
 * ADR-632 (M5) — confrontation d'une période candidate aux intervalles DÉJÀ facturés, par prestation.
 * Anti-double-facturation réel (chevauchement d'intervalles semi-ouverts `[start, end)`), au-delà du
 * simple watermark `billed_through`. Lecture/écriture scopées par (subscription, prestation, devise).
 */
class BillingCoverage
{
    /** True si la période [start, end) chevauche un intervalle déjà facturé pour cette prestation. */
    public static function overlaps(int $subscriptionId, string $prestationKey, string $currency, Carbon $start, Carbon $end): bool
    {
        return self::coverageStatus($subscriptionId, $prestationKey, $currency, $start, $end) !== 'none';
    }

    /**
     * ADR-632 (M6) — statut de couverture de [start, end) vis-à-vis des intervalles déjà facturés :
     *   - 'none'    : aucun chevauchement → la période est entièrement à facturer ;
     *   - 'full'    : un intervalle couvre [start, end) en entier → à SAUTER (anti-double) ;
     *   - 'partial' : chevauchement sans couverture totale → NE PAS ignorer silencieusement ni
     *                 facturer un résidu ambigu → BLOQUER pour revue (décision M6).
     */
    public static function coverageStatus(int $subscriptionId, string $prestationKey, string $currency, Carbon $start, Carbon $end): string
    {
        $overlapping = BillingCoveredInterval::where('subscription_id', $subscriptionId)
            ->where('prestation_key', $prestationKey)
            ->where('currency', $currency)
            ->where('period_start', '<', $end)   // semi-ouvert : coveredStart < end
            ->where('period_end', '>', $start)   //              AND start < coveredEnd
            ->get(['period_start', 'period_end']);

        if ($overlapping->isEmpty()) {
            return 'none';
        }

        // Couverture TOTALE si un seul intervalle englobe [start, end) : coveredStart <= start ET coveredEnd >= end.
        foreach ($overlapping as $iv) {
            if ($iv->period_start->lte($start) && $iv->period_end->gte($end)) {
                return 'full';
            }
        }

        // Chevauchement sans englobement total → partiel (ne jamais ignorer/auto-facturer un résidu).
        return 'partial';
    }

    /** Enregistre un intervalle facturé (à l'intérieur de la transaction d'émission). */
    public static function record(int $companyId, int $subscriptionId, string $prestationKey, string $currency, Carbon $start, Carbon $end, ?int $invoiceId, ?int $invoiceLineId): void
    {
        BillingCoveredInterval::create([
            'company_id' => $companyId,
            'subscription_id' => $subscriptionId,
            'prestation_key' => $prestationKey,
            'currency' => $currency,
            'period_start' => $start,
            'period_end' => $end,
            'invoice_id' => $invoiceId,
            'invoice_line_id' => $invoiceLineId,
            'source_model_version' => 'v2',
        ]);
    }
}
