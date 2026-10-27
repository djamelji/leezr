<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-632 (M4) — borne exclusive déjà facturée pour une prestation (plan|module) d'un abonnement.
 * `billed_through` null = inconnu → l'émission V2 de cette prestation est bloquée (jamais déduit
 * d'un historique ambigu). Isolation par subscription + currency.
 */
class BillingWatermark extends Model
{
    protected $fillable = ['company_id', 'subscription_id', 'prestation_key', 'currency', 'billed_through', 'source_model_version'];

    protected function casts(): array
    {
        return ['billed_through' => 'datetime'];
    }
}
