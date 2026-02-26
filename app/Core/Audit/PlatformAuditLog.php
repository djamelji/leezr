<?php

namespace App\Core\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-130: Platform-level audit log entry.
 *
 * Append-only — no updated_at column, no updates allowed.
 */
class PlatformAuditLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
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
}
