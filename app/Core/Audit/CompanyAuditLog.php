<?php

namespace App\Core\Audit;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-130: Company-scoped audit log entry.
 *
 * Append-only — no updated_at column, no updates allowed.
 */
class CompanyAuditLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'actor_id',
        'actor_type',
        'action',
        'target_type',
        'target_id',
        'severity',
        'diff_before',
        'diff_after',
        'correlation_id',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'diff_before' => 'array',
        'diff_after' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
