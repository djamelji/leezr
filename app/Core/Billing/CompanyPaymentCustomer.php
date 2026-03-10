<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyPaymentCustomer extends Model
{
    protected $fillable = [
        'company_id', 'provider_key',
        'provider_customer_id', 'metadata',
        'last_reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
