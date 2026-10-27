<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-632 (M4) — enregistrement d'un BillingRun consolidé. `run_identity` UNIQUE garantit l'idempotence
 * (une seule facture par company/devise/subscription/période) ; deux runs concurrents → la 2ᵉ insertion
 * viole l'unicité et récupère la facture existante.
 */
class BillingRunRecord extends Model
{
    protected $table = 'billing_runs';

    protected $fillable = ['run_identity', 'company_id', 'subscription_id', 'currency', 'period_start', 'period_end', 'invoice_id', 'status'];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'invoice_id' => 'integer'];
    }
}
