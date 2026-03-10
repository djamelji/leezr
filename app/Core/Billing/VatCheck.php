<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * VIES VAT validation cache entry (ADR-310).
 *
 * Stores the result of an EU VIES SOAP validation call.
 * Entries expire after 7 days and are refreshed on next validation.
 */
class VatCheck extends Model
{
    protected $table = 'billing_vat_checks';

    protected $fillable = [
        'vat_number',
        'country_code',
        'is_valid',
        'name',
        'address',
        'checked_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_valid' => 'boolean',
            'checked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
