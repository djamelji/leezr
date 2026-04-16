<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyWallet extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'currency', 'cached_balance',
    ];

    protected function casts(): array
    {
        return [
            'cached_balance' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CompanyWalletTransaction::class, 'wallet_id');
    }

    /**
     * Compute actual balance from transactions (source of truth).
     */
    public function computedBalance(): int
    {
        $credits = $this->transactions()->where('type', 'credit')->sum('amount');
        $debits = $this->transactions()->where('type', 'debit')->sum('amount');

        return (int) ($credits - $debits);
    }
}
