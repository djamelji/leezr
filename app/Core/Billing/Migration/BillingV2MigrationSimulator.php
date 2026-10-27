<?php

namespace App\Core\Billing\Migration;

use App\Core\Billing\BillingCoveredInterval;
use App\Core\Billing\BillingWatermark;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\PeriodBounds;
use App\Core\Billing\Subscription;
use Illuminate\Support\Collection;

/**
 * ADR-632 (M10-A) — simulateur de migration V2, STRICTEMENT EN LECTURE SEULE.
 *
 * Reconstitue, à partir des factures V1 persistées, la couverture de facturation par prestation
 * (watermarks `billed_through` + intervalles couverts) qui empêcherait toute double facturation lors
 * d'une future émission V2. N'ÉCRIT RIEN : produit un rapport avant/après et une classification
 * (éligible / revue manuelle). Les périodes ambiguës ne sont JAMAIS interprétées — la prestation
 * concernée reste « inconnue » (watermark null → l'émission V2 la bloque), et le contrat part en
 * revue manuelle. Aucune charge n'est jamais dérivée.
 *
 * Convention (identique au BillingRun) : prestation_key = 'plan' pour le plan, module_key sinon ;
 * période semi-ouverte [start, end) ; borne exclusive via PeriodBounds::fromLegacy.
 */
class BillingV2MigrationSimulator
{
    /** Abonnements candidats : actifs et porteurs d'un plan. (L'éligibilité V2 — v2_effective_at — est une décision séparée.) */
    public static function candidates(?int $companyId = null): Collection
    {
        return Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('plan_key')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('company_id')
            ->get();
    }

    public static function simulateAll(?int $companyId = null): array
    {
        $reports = static::candidates($companyId)->map(fn (Subscription $s) => static::simulateSubscription($s))->all();

        $eligible = array_values(array_filter($reports, fn ($r) => $r['classification'] === 'eligible'));
        $manual = array_values(array_filter($reports, fn ($r) => $r['classification'] === 'manual_review'));

        return [
            'generated_for_company' => $companyId,
            'total_candidates' => count($reports),
            'eligible_count' => count($eligible),
            'manual_review_count' => count($manual),
            'block_reason_histogram' => static::histogram($manual),
            'reports' => $reports,
            'readonly' => true,
        ];
    }

    public static function simulateSubscription(Subscription $sub): array
    {
        $company = $sub->company; // belongsTo (read only)
        $anomalies = [];

        // ── V1 invoices owned by this subscription (parents + their annexes), finalized & not void ──
        $parentIds = Invoice::where('subscription_id', $sub->id)->pluck('id');
        $invoices = Invoice::query()
            ->whereNotNull('finalized_at')
            ->where('status', '!=', 'void')
            ->where('billing_model_version', '!=', 2)
            ->where(function ($q) use ($sub, $parentIds) {
                $q->where('subscription_id', $sub->id);
                if ($parentIds->isNotEmpty()) {
                    $q->orWhereIn('parent_invoice_id', $parentIds);
                }
            })
            ->with('lines')
            ->get();

        // Currency = what was actually billed on V1 (single source). Multiple → anomaly.
        $currencies = $invoices->pluck('currency')->filter()->unique()->values();
        $currency = $currencies->first();
        if ($currencies->count() > 1) {
            $anomalies[] = 'multi_currency:'.$currencies->implode(',');
        }

        // ── Reconstruct coverage per prestation from V1 invoice lines ──
        // $byKey[prestation] = ['intervals' => [[startIso, endIso, invId, lineId], ...], 'ambiguous' => bool]
        $byKey = [];
        foreach ($invoices as $inv) {
            foreach ($inv->lines as $line) {
                if (! in_array($line->type, ['plan', 'addon'], true)) {
                    continue; // only recurring prestations define coverage
                }
                $key = $line->type === 'plan' ? 'plan' : ($line->module_key ?: 'plan');
                $byKey[$key] ??= ['intervals' => [], 'ambiguous' => false];

                if (! $line->period_start || ! $line->period_end) {
                    $byKey[$key]['ambiguous'] = true;
                    $anomalies[] = "line_without_period:{$key}:inv{$inv->id}";

                    continue;
                }
                $pb = PeriodBounds::fromLegacy($line->period_start, $line->period_end);
                if (! $pb->isResolved() || $pb->endExclusive === null) {
                    $byKey[$key]['ambiguous'] = true;
                    $anomalies[] = "ambiguous_period:{$key}:inv{$inv->id}";

                    continue;
                }
                $byKey[$key]['intervals'][] = [$pb->start->toIso8601String(), $pb->endExclusive->toIso8601String(), $inv->id, $line->id];
            }
        }

        // ── Overlap detection per prestation (semi-open) + proposed watermark/coverage ──
        $proposedCoverage = [];
        $proposedWatermarks = [];
        $overlapRisk = false;
        foreach ($byKey as $key => $data) {
            $intervals = $data['intervals'];
            usort($intervals, fn ($a, $b) => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($intervals); $i++) {
                if (strcmp($intervals[$i][0], $intervals[$i - 1][1]) < 0) { // start < prevEnd → chevauchement
                    $overlapRisk = true;
                    $anomalies[] = "overlap:{$key}";
                    break;
                }
            }
            foreach ($intervals as [$s, $e, $invId, $lineId]) {
                $proposedCoverage[] = ['prestation_key' => $key, 'period_start' => $s, 'period_end' => $e, 'invoice_id' => $invId, 'invoice_line_id' => $lineId];
            }
            // billed_through = borne couverte max SEULEMENT si aucune ligne ambiguë ; sinon inconnu (null → bloque V2).
            $proposedWatermarks[] = [
                'prestation_key' => $key,
                'billed_through' => $data['ambiguous'] ? null : (count($intervals) ? max(array_column($intervals, 1)) : null),
                'unknown' => $data['ambiguous'],
            ];
        }

        // ── Current period classification (datetime source = Subscription) ──
        $pbCur = ($sub->current_period_start && $sub->current_period_end)
            ? PeriodBounds::fromLegacy($sub->current_period_start, $sub->current_period_end)
            : null;
        $annTime = $sub->current_period_start?->format('H:i:s');
        $annNonMidnight = $annTime !== null && $annTime !== '00:00:00';

        // ── Active modules (coverage targets besides the plan) ──
        $modules = CompanyAddonSubscription::where('company_id', $sub->company_id)
            ->whereNull('deactivated_at')
            ->get(['module_key', 'amount_cents', 'currency', 'activated_at'])
            ->map(fn ($m) => [
                'module_key' => $m->module_key,
                'amount_cents' => (int) $m->amount_cents,
                'activated_at' => $m->activated_at?->toIso8601String(),
                // Module facturé « mois plein depuis activation » non aligné au cycle plan : détecté si
                // aucun intervalle reconstruit ne démarre au début du cycle plan courant.
                'has_coverage' => isset($byKey[$m->module_key]) && count($byKey[$m->module_key]['intervals']) > 0,
            ])->all();

        // ── Reliability + classification ──
        $priceReliable = $sub->hasReliableContractedPrice();
        $blockReasons = [];
        if (! $priceReliable) {
            $blockReasons[] = 'provisional_or_unreliable_price';
        }
        if ($pbCur === null) {
            $blockReasons[] = 'no_current_period';
        } elseif (! $pbCur->isResolved()) {
            $blockReasons[] = 'ambiguous_current_period';
        }
        if (collect($proposedWatermarks)->contains('unknown', true)) {
            $blockReasons[] = 'ambiguous_coverage';
        }
        if ($overlapRisk) {
            $blockReasons[] = 'coverage_overlap';
        }
        if ($currencies->count() > 1) {
            $blockReasons[] = 'multi_currency';
        }

        $classification = empty($blockReasons) ? 'eligible' : 'manual_review';

        // ── Before/after (existing V2 coverage rows vs proposed) — 0 avant sur PROD ──
        $before = [
            'watermarks' => BillingWatermark::where('subscription_id', $sub->id)->count(),
            'covered_intervals' => BillingCoveredInterval::where('subscription_id', $sub->id)->count(),
        ];

        return [
            'company_id' => $sub->company_id,
            'company_name' => $company?->name,
            'subscription_id' => $sub->id,
            'plan_key' => $sub->plan_key,
            'interval' => $sub->interval,
            'currency' => $currency,
            'anniversary_time' => $annTime,
            'anniversary_non_midnight' => $annNonMidnight,
            'current_period' => $pbCur ? [
                'start' => $sub->current_period_start?->toIso8601String(),
                'end' => $sub->current_period_end?->toIso8601String(),
                'classification' => $pbCur->classification,
                'resolved' => $pbCur->isResolved(),
            ] : null,
            'price_source' => $sub->plan_price_source,
            'price_reliable' => $priceReliable,
            'v2_effective_at' => $sub->v2_effective_at?->toIso8601String(),
            'active_modules' => $modules,
            'v1_invoices_count' => $invoices->where('parent_invoice_id', null)->count(),
            'v1_annexes_count' => $invoices->whereNotNull('parent_invoice_id')->count(),
            'payments_count' => Payment::where('company_id', $sub->company_id)
                ->whereIn('invoice_id', $invoices->pluck('id'))->count(),
            'credit_notes_count' => CreditNote::whereIn('invoice_id', $invoices->pluck('id'))->count(),
            'proposed_coverage' => $proposedCoverage,
            'proposed_watermarks' => $proposedWatermarks,
            'anomalies' => array_values(array_unique($anomalies)),
            'overlap_risk' => $overlapRisk,
            'classification' => $classification,
            'block_reasons' => $blockReasons,
            'before' => $before,
            'after' => [
                'watermarks' => count($proposedWatermarks),
                'covered_intervals' => count($proposedCoverage),
            ],
        ];
    }

    private static function histogram(array $manualReports): array
    {
        $h = [];
        foreach ($manualReports as $r) {
            foreach ($r['block_reasons'] as $reason) {
                $h[$reason] = ($h[$reason] ?? 0) + 1;
            }
        }
        arsort($h);

        return $h;
    }
}
