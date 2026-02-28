<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-141 D3e: Pre-mutation snapshot for auto-repair audit trail.
 *
 * Every auto-repair mutation takes a snapshot BEFORE modifying data.
 * Immutable after creation — never update a snapshot.
 */
class FinancialSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'trigger',
        'drift_type',
        'entity_type',
        'entity_id',
        'snapshot_data',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'snapshot_data' => 'array',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
