<?php

namespace App\Core\Billing;

use App\Core\Billing\DTOs\BillingRunSimulation;
use App\Core\Billing\DTOs\CandidateInvoiceLine;
use App\Core\Billing\DTOs\EmissionResult;
use App\Core\Billing\DTOs\PriceLine;
use App\Core\Models\Company;
use Illuminate\Database\QueryException;
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

    /**
     * ADR-632 (M4) — ÉMISSION consolidée transactionnelle. DÉSACTIVÉE par défaut
     * (`billing.consolidated.emit` = false) : en environnement déployé, rien n'est émis.
     * Aucun paiement / wallet / SEPA / webhook / notification (finalize suppress). Atomique :
     * aucune facture partielle ne survit à un rollback ; aucun event INVOICED sans sa facture.
     */
    public function emit(Subscription $sub): EmissionResult
    {
        if (! config('billing.consolidated.emit', false)) {
            return EmissionResult::disabled();
        }
        if ($sub->v2_effective_at === null || $sub->v2_effective_at->isFuture()) {
            return EmissionResult::blocked(['not_eligible']);
        }
        if (! $sub->hasReliableContractedPrice()) {
            return EmissionResult::blocked(['cycle_hold', 'pricing_review_required']); // ADR-593
        }

        $pb = PeriodBounds::fromLegacy($sub->current_period_start, $sub->current_period_end);
        if (! $pb->isResolved()) {
            return EmissionResult::blocked(['ambiguous_period']);
        }

        $company = Company::findOrFail($sub->company_id);
        $periodStart = Carbon::parse($sub->current_period_start)->utc();
        $periodEnd = Carbon::parse($pb->endExclusive);
        $breakdown = PricingEngine::forCurrentPeriod($sub, $company);
        $currency = $breakdown->currency;
        $rateBps = $breakdown->taxRateBps;
        $suppressInclusive = PlatformBillingPolicy::instance()->tax_mode === 'inclusive';

        // Watermark : une prestation dont le watermark est EXPLICITEMENT inconnu (row, billed_through null)
        // bloque l'émission. Absence de row = première facturation (autorisée). Déjà couverte (>= fin) = skip.
        $prestations = array_merge(['plan'], array_values(array_filter(array_map(
            fn (PriceLine $l) => $l->moduleKey, $breakdown->addonLines(),
        ))));
        $watermarks = BillingWatermark::where('subscription_id', $sub->id)->where('currency', $currency)
            ->get()->keyBy('prestation_key');
        foreach ($prestations as $p) {
            $wm = $watermarks->get($p);
            if ($wm && $wm->billed_through === null) {
                return EmissionResult::blocked(['watermark_unknown:'.$p]);
            }
        }

        $policyVersion = $sub->commercial_policy_version ?? 'v1';
        $runIdentity = sprintf('run:%d:%s:%d:%s:%s', $company->id, $currency, $sub->id, $periodStart->toIso8601String(), $policyVersion);

        try {
            return DB::transaction(function () use ($sub, $company, $currency, $rateBps, $suppressInclusive, $breakdown, $periodStart, $periodEnd, $watermarks, $runIdentity) {
                // Idempotence : 1 facture par run_identity.
                $run = BillingRunRecord::where('run_identity', $runIdentity)->lockForUpdate()->first();
                if ($run && $run->invoice_id) {
                    return EmissionResult::replayed($run->invoice_id, $runIdentity);
                }
                if (! $run) {
                    $run = BillingRunRecord::create([
                        'run_identity' => $runIdentity, 'company_id' => $company->id, 'subscription_id' => $sub->id,
                        'currency' => $currency, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'status' => 'emitted',
                    ]);
                }

                $invoice = InvoiceIssuer::createDraft($company, $sub->id, $periodStart->toDateString(), $periodEnd->toDateString());
                $invoice->billing_model_version = 2;
                $invoice->save();

                $billedPrestations = [];

                // Lignes récurrentes (plan + modules) — skip celles déjà couvertes (watermark OU intervalle).
                $coverageToRecord = [];
                foreach ($breakdown->lines as $pl) {
                    /** @var PriceLine $pl */
                    if (in_array($pl->type, ['plan', 'addon'], true)) {
                        $key = $pl->type === 'plan' ? 'plan' : $pl->moduleKey;
                        $wm = $watermarks->get($key);
                        if (($wm && $wm->billed_through && $wm->billed_through->gte($periodEnd))
                            || BillingCoverage::overlaps($sub->id, $key, $currency, $periodStart, $periodEnd)) {
                            continue; // déjà couverte (watermark ou intervalle facturé) → pas de double
                        }
                        $billedPrestations[$key] = true;
                    }
                    $line = InvoiceIssuer::addLine($invoice, $pl->type, $pl->description, $pl->unitAmount, $pl->quantity, $pl->moduleKey,
                        $periodStart->toDateString(), $periodEnd->toDateString());
                    if (isset($key) && isset($billedPrestations[$key])) {
                        $coverageToRecord[] = [$key, $periodStart, $periodEnd, $line->id];
                    }
                    $key = null;
                }

                // Proratas antérieurs : réserver puis facturer + lier (markInvoiced).
                $eligibleIds = BillableEvent::where('company_id', $company->id)->where('currency', $currency)
                    ->where('subscription_id', $sub->id)->where('status', BillableEvent::STATUS_ELIGIBLE)->pluck('id')->all();
                $reserved = app(BillableEventService::class)->reserve($runIdentity, $company->id, $currency, $eligibleIds);
                $eventsInvoiced = 0;
                foreach ($reserved as $ev) {
                    $line = InvoiceIssuer::addLine($invoice, $ev->module_key ? 'module' : 'adjustment',
                        ($ev->module_key ?? $ev->type).' — prorata', (int) $ev->amount_ht_cents, 1, $ev->module_key,
                        optional($ev->service_period_start)->toDateString(), optional($ev->service_period_end)->toDateString());
                    app(BillableEventService::class)->markInvoiced($ev->id, $line->id, $runIdentity);
                    if ($ev->module_key && $ev->service_period_start && $ev->service_period_end) {
                        $coverageToRecord[] = [$ev->module_key, Carbon::parse($ev->service_period_start), Carbon::parse($ev->service_period_end), $line->id];
                    }
                    $eventsInvoiced++;
                }

                if ($invoice->fresh()->lines()->count() === 0) {
                    throw new \RuntimeException('__nothing_to_bill__'); // rollback propre, aucune facture partielle
                }

                $finalized = InvoiceIssuer::finalize($invoice, suppressExternalSideEffects: true);

                // Avancer les watermarks des prestations facturées (borne exclusive = fin de période).
                foreach (array_keys($billedPrestations) as $p) {
                    BillingWatermark::updateOrCreate(
                        ['subscription_id' => $sub->id, 'prestation_key' => $p, 'currency' => $currency],
                        ['company_id' => $company->id, 'billed_through' => $periodEnd, 'source_model_version' => 'v2'],
                    );
                }

                // Enregistrer les intervalles couverts (preuve anti-double pour les cycles suivants / immédiat).
                foreach ($coverageToRecord as [$pk, $cs, $ce, $lineId]) {
                    BillingCoverage::record($company->id, $sub->id, $pk, $currency, $cs, $ce, $finalized->id, $lineId);
                }

                $run->update(['invoice_id' => $finalized->id, 'period_end' => $periodEnd]);

                return new EmissionResult(
                    status: 'emitted', invoiceId: $finalized->id, runIdentity: $runIdentity,
                    linesCount: $finalized->lines()->count(), subtotalHtCents: $finalized->subtotal,
                    taxAmountCents: $finalized->tax_amount, totalTtcCents: $finalized->amount, eventsInvoiced: $eventsInvoiced,
                );
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === '__nothing_to_bill__') {
                return EmissionResult::blocked(['nothing_to_bill'], $runIdentity);
            }
            throw $e;
        } catch (QueryException $e) {
            // Course concurrente sur run_identity → récupérer la facture émise par le run gagnant.
            $run = BillingRunRecord::where('run_identity', $runIdentity)->first();
            if ($run && $run->invoice_id) {
                return EmissionResult::replayed($run->invoice_id, $runIdentity);
            }
            throw $e;
        }
    }

    /**
     * ADR-632 (M5) — ÉMISSION IMMÉDIATE HORS CYCLE d'un événement `bill_immediately` (politique
     * contractuelle). Même protocole atomique que emit(), document autonome ne portant QUE cet
     * événement. DÉSACTIVÉE par défaut (billing.consolidated.emit). Anti-double : l'intervalle facturé
     * est confronté aux intervalles déjà couverts ; il sera ensuite ignoré par la facture récurrente.
     */
    public function emitImmediate(BillableEvent $event): EmissionResult
    {
        if (! config('billing.consolidated.emit', false)) {
            return EmissionResult::disabled();
        }
        if (! $event->bill_immediately) {
            return EmissionResult::blocked(['not_immediate']);
        }
        // Idempotence AVANT la garde d'état : un rejeu (event déjà facturé par CE run) retourne la
        // facture existante plutôt qu'un refus.
        $priorRun = BillingRunRecord::where('run_identity', 'run:imm:'.$event->id)->first();
        if ($priorRun && $priorRun->invoice_id) {
            return EmissionResult::replayed($priorRun->invoice_id, $priorRun->run_identity);
        }
        if ($event->status !== BillableEvent::STATUS_ELIGIBLE) {
            return EmissionResult::blocked(['event_not_eligible']);
        }
        if (! $event->service_period_start || ! $event->service_period_end) {
            return EmissionResult::blocked(['ambiguous_period']);
        }

        $sub = Subscription::findOrFail($event->subscription_id);
        if ($sub->v2_effective_at === null || $sub->v2_effective_at->isFuture()) {
            return EmissionResult::blocked(['not_eligible']);
        }
        if (! $sub->hasReliableContractedPrice()) {
            return EmissionResult::blocked(['cycle_hold', 'pricing_review_required']); // ADR-593 : hors cycle aussi
        }

        $company = Company::findOrFail($event->company_id);
        $currency = $event->currency;
        $start = Carbon::parse($event->service_period_start)->utc();
        $end = Carbon::parse($event->service_period_end);
        $prestation = $event->module_key ?? 'adjustment';

        if (BillingCoverage::overlaps($sub->id, $prestation, $currency, $start, $end)) {
            return EmissionResult::blocked(['already_covered']);
        }

        $runIdentity = 'run:imm:'.$event->id;

        try {
            return DB::transaction(function () use ($event, $sub, $company, $currency, $start, $end, $prestation, $runIdentity) {
                $run = BillingRunRecord::where('run_identity', $runIdentity)->lockForUpdate()->first();
                if ($run && $run->invoice_id) {
                    return EmissionResult::replayed($run->invoice_id, $runIdentity);
                }
                if (! $run) {
                    $run = BillingRunRecord::create([
                        'run_identity' => $runIdentity, 'company_id' => $company->id, 'subscription_id' => $sub->id,
                        'currency' => $currency, 'period_start' => $start, 'period_end' => $end, 'status' => 'emitted',
                    ]);
                }

                $reserved = app(BillableEventService::class)->reserve($runIdentity, $company->id, $currency, [$event->id]);
                if ($reserved->isEmpty()) {
                    throw new \RuntimeException('__nothing_to_bill__'); // course : déjà réservé/facturé
                }

                $invoice = InvoiceIssuer::createDraft($company, $sub->id, $start->toDateString(), $end->toDateString());
                $invoice->billing_model_version = 2;
                $invoice->save();

                $line = InvoiceIssuer::addLine($invoice, $event->module_key ? 'module' : 'adjustment',
                    ($event->module_key ?? $event->type).' — facturation immédiate', (int) $event->amount_ht_cents, 1, $event->module_key,
                    $start->toDateString(), $end->toDateString());
                app(BillableEventService::class)->markInvoiced($event->id, $line->id, $runIdentity);

                $finalized = InvoiceIssuer::finalize($invoice, suppressExternalSideEffects: true);

                BillingCoverage::record($company->id, $sub->id, $prestation, $currency, $start, $end, $finalized->id, $line->id);
                $run->update(['invoice_id' => $finalized->id]);

                return new EmissionResult(
                    status: 'emitted', invoiceId: $finalized->id, runIdentity: $runIdentity,
                    linesCount: 1, subtotalHtCents: $finalized->subtotal, taxAmountCents: $finalized->tax_amount,
                    totalTtcCents: $finalized->amount, eventsInvoiced: 1,
                );
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === '__nothing_to_bill__') {
                return EmissionResult::blocked(['nothing_to_bill'], $runIdentity);
            }
            throw $e;
        } catch (QueryException $e) {
            $run = BillingRunRecord::where('run_identity', $runIdentity)->first();
            if ($run && $run->invoice_id) {
                return EmissionResult::replayed($run->invoice_id, $runIdentity);
            }
            throw $e;
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
