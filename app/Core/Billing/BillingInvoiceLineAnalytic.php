<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-632 (M7) — ligne de ventilation analytique d'une facture consolidée V2. PAS une créance,
 * PAS un grand livre : décomposition réconciliable (Σ ht = subtotal, Σ tax = TVA agrégée, Σ ttc = total).
 */
class BillingInvoiceLineAnalytic extends Model
{
    protected $fillable = [
        'invoice_id', 'invoice_line_id', 'company_id', 'subscription_id', 'prestation_type', 'module_key',
        'service_period_start', 'service_period_end', 'ht_cents', 'tax_rate_bps', 'tax_cents', 'ttc_cents',
        'currency', 'billable_event_id',
    ];

    protected function casts(): array
    {
        return [
            'service_period_start' => 'datetime', 'service_period_end' => 'datetime',
            'ht_cents' => 'integer', 'tax_rate_bps' => 'integer', 'tax_cents' => 'integer', 'ttc_cents' => 'integer',
            'invoice_id' => 'integer', 'invoice_line_id' => 'integer', 'billable_event_id' => 'integer',
        ];
    }
}
