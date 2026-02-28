<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ADR-142 D3f: Immutable double-entry financial ledger.
 *
 * Append-only — never UPDATE, never DELETE.
 * boot() enforces immutability at the application level.
 */
class LedgerEntry extends Model
{
    protected $table = 'financial_ledger_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new RuntimeException('Ledger entries are immutable — updates are forbidden.');
        });

        static::deleting(function () {
            throw new RuntimeException('Ledger entries are immutable — deletes are forbidden.');
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
