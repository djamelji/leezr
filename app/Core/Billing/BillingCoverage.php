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
        return BillingCoveredInterval::where('subscription_id', $subscriptionId)
            ->where('prestation_key', $prestationKey)
            ->where('currency', $currency)
            // chevauchement semi-ouvert : coveredStart < end AND start < coveredEnd
            ->where('period_start', '<', $end)
            ->where('period_end', '>', $start)
            ->exists();
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
