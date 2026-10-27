<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-632 (M1) — événement facturable immuable (plan/module/addon/ajustement) en attente de
 * consolidation par un BillingRun (M2+). NON branché au moteur en M1.
 *
 * Isolation multi-tenant : volontairement SANS le global scope BelongsToCompany (no-op hors contexte
 * HTTP). L'isolation est imposée EXPLICITEMENT par `BillableEventService` (clause `company_id` dans
 * chaque requête) + contraintes DB uniques.
 *
 * Machine d'état (simplifiée vs brouillon — pas d'état CREATED distinct : `record()` crée directement
 * ELIGIBLE, la création EST l'éligibilité) :
 *   ELIGIBLE ──reserve──▶ RESERVED ──markInvoiced──▶ INVOICED  (terminal)
 *      │                     │
 *      │                     └──releaseStale(lease expiré)──▶ ELIGIBLE
 *      └──cancel──▶ CANCELLED (terminal)
 *   Compensation = NOUVEL event (compensation_of_event_id) ; l'original n'est jamais muté/supprimé.
 */
class BillableEvent extends Model
{
    public const STATUS_ELIGIBLE = 'eligible';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_INVOICED = 'invoiced';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPENSATED = 'compensated';

    protected $fillable = [
        'company_id', 'subscription_id', 'currency', 'type', 'module_key', 'source_ref',
        'service_date', 'service_period_start', 'service_period_end',
        'contractual_period_start', 'contractual_period_end',
        'price_snapshot_cents', 'proration_num', 'proration_den', 'rounding', 'amount_ht_cents',
        'tax_context_snapshot', 'commercial_policy_version',
        'status', 'bill_immediately', 'reserved_by_run_id', 'reserved_at', 'invoice_line_id', 'compensation_of_event_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'datetime',
            'service_period_start' => 'datetime',
            'service_period_end' => 'datetime',
            'contractual_period_start' => 'datetime',
            'contractual_period_end' => 'datetime',
            'price_snapshot_cents' => 'integer',
            'proration_num' => 'integer',
            'proration_den' => 'integer',
            'amount_ht_cents' => 'integer',
            'tax_context_snapshot' => 'array',
            'reserved_at' => 'datetime',
            'bill_immediately' => 'boolean',
            'invoice_line_id' => 'integer',
            'compensation_of_event_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Empreinte des champs financièrement significatifs — pour détecter un même idempotency_key
     * réutilisé avec un payload divergent (conflit explicite).
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'company_id' => $this->company_id,
            'currency' => $this->currency,
            'type' => $this->type,
            'module_key' => $this->module_key,
            'service_period_start' => optional($this->service_period_start)->toIso8601String(),
            'service_period_end' => optional($this->service_period_end)->toIso8601String(),
            'price_snapshot_cents' => $this->price_snapshot_cents,
            'amount_ht_cents' => $this->amount_ht_cents,
            'proration_num' => $this->proration_num,
            'proration_den' => $this->proration_den,
        ]));
    }
}
