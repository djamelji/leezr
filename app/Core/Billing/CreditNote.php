<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNote extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'number', 'company_id', 'invoice_id',
        'amount', 'currency', 'reason', 'status',
        'issued_at', 'applied_at', 'wallet_transaction_id',
        'billing_snapshot', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'issued_at' => 'datetime',
            'applied_at' => 'datetime',
            'wallet_transaction_id' => 'integer',
            'billing_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(CompanyWalletTransaction::class, 'wallet_transaction_id');
    }

    public function isApplied(): bool
    {
        return $this->status === 'applied' && $this->wallet_transaction_id !== null;
    }
}
