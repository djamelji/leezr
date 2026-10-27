<?php

namespace App\Core\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-632 (M1) — moteur d'événements facturables. Composant de DOMAINE autonome, idempotent,
 * transactionnel, multi-tenant. NON branché au moteur de facturation (aucun appel depuis
 * InvoiceIssuer / BillingRenewCommand / AddonBillingListener / scheduler). Ne crée aucune facture,
 * ne déclenche aucun paiement. Prépare M2 (BillingRun) sans exécuter de consolidation.
 *
 * Isolation : chaque requête filtre explicitement `company_id` (clause SQL), + contraintes DB UNIQUE
 * (`idempotency_key`, `invoice_line_id`). La concurrence s'appuie sur des UPDATE conditionnels
 * atomiques, pas sur des vérifications PHP.
 */
class BillableEventService
{
    private const TYPES = ['plan_activation', 'module_activation', 'module_deactivation', 'plan_change', 'adjustment', 'discount'];

    /**
     * Enregistre un événement (status ELIGIBLE). Idempotent sur `idempotency_key` :
     *   - même clé + même payload → retourne l'événement existant ;
     *   - même clé + payload divergent → conflit explicite.
     */
    public function record(array $data): BillableEvent
    {
        $this->validate($data);

        $candidate = new BillableEvent(array_merge([
            'status' => BillableEvent::STATUS_ELIGIBLE,
            'rounding' => 'floor',
        ], $data));

        $existing = BillableEvent::where('company_id', $data['company_id'])
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            if ($existing->fingerprint() !== $candidate->fingerprint()) {
                throw BillableEventException::conflict("idempotency_key '{$data['idempotency_key']}' réutilisée avec un payload divergent");
            }

            return $existing; // rejeu idempotent
        }

        try {
            $candidate->save();
        } catch (QueryException $e) {
            // Course entre deux record() concurrents sur la même clé → l'un gagne, l'autre réconcilie.
            $raced = BillableEvent::where('company_id', $data['company_id'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($raced && $raced->fingerprint() === $candidate->fingerprint()) {
                return $raced;
            }
            throw BillableEventException::conflict("idempotency_key '{$data['idempotency_key']}' en conflit");
        }

        return $candidate;
    }

    /**
     * Réserve atomiquement, pour un run, les événements ELIGIBLE donnés (scopés company + devise).
     * Deux runs concurrents : seul le premier UPDATE transitionne une ligne donnée.
     *
     * @return Collection<BillableEvent> les événements effectivement réservés PAR CE run
     */
    public function reserve(string $runId, int $companyId, string $currency, array $eventIds): Collection
    {
        if (empty($eventIds)) {
            return collect();
        }

        DB::table('billable_events')
            ->whereIn('id', $eventIds)
            ->where('company_id', $companyId)
            ->where('currency', $currency)
            ->where('status', BillableEvent::STATUS_ELIGIBLE)
            ->update([
                'status' => BillableEvent::STATUS_RESERVED,
                'reserved_by_run_id' => $runId,
                'reserved_at' => now(),
                'updated_at' => now(),
            ]);

        return BillableEvent::whereIn('id', $eventIds)
            ->where('company_id', $companyId)
            ->where('status', BillableEvent::STATUS_RESERVED)
            ->where('reserved_by_run_id', $runId)
            ->get();
    }

    /**
     * Associe DÉFINITIVEMENT un événement réservé à une InvoiceLine (RESERVED → INVOICED).
     * Idempotent si déjà invoiced avec la même ligne. `invoice_line_id` est UNIQUE en base :
     * deux événements ne peuvent pointer la même ligne.
     */
    public function markInvoiced(int $eventId, int $invoiceLineId, string $runId): BillableEvent
    {
        $e = BillableEvent::findOrFail($eventId);

        if ($e->status === BillableEvent::STATUS_INVOICED) {
            if ((int) $e->invoice_line_id === $invoiceLineId) {
                return $e; // idempotent
            }
            throw BillableEventException::conflict("event #{$eventId} déjà facturé sur une autre ligne");
        }

        if ($e->status !== BillableEvent::STATUS_RESERVED) {
            throw BillableEventException::illegalTransition($e->status, BillableEvent::STATUS_INVOICED);
        }

        if ($e->reserved_by_run_id !== $runId) {
            throw BillableEventException::conflict("event #{$eventId} réservé par un autre run");
        }

        try {
            $e->update([
                'status' => BillableEvent::STATUS_INVOICED,
                'invoice_line_id' => $invoiceLineId,
            ]);
        } catch (QueryException $e2) {
            throw BillableEventException::conflict("invoice_line_id {$invoiceLineId} déjà associé à un autre événement");
        }

        return $e->fresh();
    }

    /** Annule un événement NON facturé. Autorisé uniquement depuis ELIGIBLE. */
    public function cancel(int $eventId): BillableEvent
    {
        $e = BillableEvent::findOrFail($eventId);

        if ($e->status !== BillableEvent::STATUS_ELIGIBLE) {
            throw BillableEventException::illegalTransition($e->status, BillableEvent::STATUS_CANCELLED);
        }

        $e->update(['status' => BillableEvent::STATUS_CANCELLED]);

        return $e->fresh();
    }

    /**
     * Crée un événement COMPENSATOIRE traçable (nouvel enregistrement ELIGIBLE référençant l'original).
     * L'original n'est JAMAIS muté ni supprimé. Ne crée aucune facture ni remboursement.
     * Par défaut : montant inversé, type `adjustment`.
     */
    public function compensate(int $eventId, array $overrides = []): BillableEvent
    {
        $orig = BillableEvent::findOrFail($eventId);

        $data = array_merge([
            'company_id' => $orig->company_id,
            'subscription_id' => $orig->subscription_id,
            'currency' => $orig->currency,
            'type' => 'adjustment',
            'module_key' => $orig->module_key,
            'source_ref' => 'compensation:'.$orig->id,
            'service_period_start' => $orig->service_period_start,
            'service_period_end' => $orig->service_period_end,
            'price_snapshot_cents' => $orig->price_snapshot_cents,
            'amount_ht_cents' => $orig->amount_ht_cents !== null ? -$orig->amount_ht_cents : null,
            'tax_context_snapshot' => $orig->tax_context_snapshot,
            'commercial_policy_version' => $orig->commercial_policy_version,
            'compensation_of_event_id' => $orig->id,
            'idempotency_key' => 'compensate:'.$orig->id,
            'status' => BillableEvent::STATUS_ELIGIBLE,
        ], $overrides);

        return $this->record($data);
    }

    /**
     * Reprise : libère UNIQUEMENT les réservations dont le lease a expiré (reserved_at < now − TTL).
     * Une réservation récente (run encore vivant dans son TTL) n'est jamais libérée.
     *
     * @return int nombre d'événements re-rendus éligibles
     */
    public function releaseStale(int $leaseTtlSeconds): int
    {
        $cutoff = Carbon::now()->subSeconds($leaseTtlSeconds);

        return BillableEvent::where('status', BillableEvent::STATUS_RESERVED)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $cutoff)
            ->update([
                'status' => BillableEvent::STATUS_ELIGIBLE,
                'reserved_by_run_id' => null,
                'reserved_at' => null,
            ]);
    }

    private function validate(array $d): void
    {
        foreach (['company_id', 'currency', 'type', 'idempotency_key'] as $req) {
            if (empty($d[$req])) {
                throw BillableEventException::invalid("champ requis manquant : {$req}");
            }
        }
        if (! in_array($d['type'], self::TYPES, true)) {
            throw BillableEventException::invalid("type invalide : {$d['type']}");
        }
        if (strlen((string) $d['currency']) !== 3) {
            throw BillableEventException::invalid('devise doit être un code ISO 3 lettres');
        }
        $start = $d['service_period_start'] ?? null;
        $end = $d['service_period_end'] ?? null;
        if ($start && $end && Carbon::parse($end)->lte(Carbon::parse($start))) {
            throw BillableEventException::invalid('service_period_end doit être strictement après service_period_start (bornes semi-ouvertes)');
        }
        $num = $d['proration_num'] ?? null;
        $den = $d['proration_den'] ?? null;
        if ($num !== null || $den !== null) {
            if (! $den || $den <= 0) {
                throw BillableEventException::invalid('proration_den doit être > 0');
            }
            if ($num === null || $num < 0 || $num > $den) {
                throw BillableEventException::invalid('proration_num doit être dans [0, proration_den]');
            }
        }
    }
}
