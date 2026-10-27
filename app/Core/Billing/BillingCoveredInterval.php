<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-632 (M5) — intervalle de service `[period_start, period_end)` réellement facturé pour une
 * prestation (plan|module) d'un abonnement. Preuve de couverture anti-double-facturation (gère les
 * cas disjoints : immédiat hors cycle, réactivation, périodes multiples).
 */
class BillingCoveredInterval extends Model
{
    protected $fillable = [
        'company_id', 'subscription_id', 'prestation_key', 'currency',
        'period_start', 'period_end', 'invoice_id', 'invoice_line_id', 'source_model_version',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'invoice_id' => 'integer', 'invoice_line_id' => 'integer'];
    }
}
