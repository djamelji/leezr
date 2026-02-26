<?php

namespace App\Core\Security;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-129: Security alert model.
 *
 * Append-only for creation; status transitions allowed (open → acknowledged → resolved).
 */
class SecurityAlert extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'alert_type',
        'severity',
        'company_id',
        'actor_id',
        'evidence',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_by',
        'resolved_at',
        'created_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'created_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
