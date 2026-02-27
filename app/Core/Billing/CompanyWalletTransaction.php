<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable append-only ledger entry.
 * No updated_at — transactions are never modified.
 */
class CompanyWalletTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'balance_after',
        'source_type', 'source_id', 'description',
        'actor_type', 'actor_id',
        'idempotency_key', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'source_id' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CompanyWallet::class, 'wallet_id');
    }
}
