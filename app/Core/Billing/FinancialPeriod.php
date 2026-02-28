<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-143 D3g: Financial period for ledger locking.
 *
 * Once closed, no normal ledger entries may be recorded
 * within the period's date range — only adjustments.
 */
class FinancialPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }
}
