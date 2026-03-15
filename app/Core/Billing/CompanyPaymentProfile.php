<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyPaymentProfile extends Model
{
    protected $fillable = [
        'company_id', 'provider_key', 'method_key',
        'provider_payment_method_id', 'label',
        'is_default', 'preferred_debit_day', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'preferred_debit_day' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Format this profile for API response (card/SEPA display).
     */
    public function toCardArray(): array
    {
        $bankCode = $this->metadata['bank_code'] ?? null;

        return [
            'id' => $this->id,
            'provider_payment_method_id' => $this->provider_payment_method_id,
            'label' => $this->label,
            'is_default' => $this->is_default,
            'method_key' => $this->method_key,
            'brand' => $this->metadata['brand'] ?? null,
            'last4' => $this->metadata['last4'] ?? null,
            'exp_month' => $this->metadata['exp_month'] ?? null,
            'exp_year' => $this->metadata['exp_year'] ?? null,
            'country' => $this->metadata['country'] ?? null,
            'funding' => $this->metadata['funding'] ?? null,
            'bank_code' => $bankCode,
            'bank_name' => $bankCode ? BicRegistry::resolve($bankCode) : null,
            'holder_name' => $this->metadata['holder_name'] ?? null,
        ];
    }
}
