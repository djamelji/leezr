<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyPaymentCustomer extends Model
{
    use BelongsToCompany;

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
}
