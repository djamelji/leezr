<?php

namespace App\Core\Workforce\Dsn;

use Illuminate\Database\Eloquent\Model;

/**
 * DSN submission lock — prevents double-submit of a declaration.
 *
 * Sprint 6.8 — ADR-526
 */
class DsnSubmissionLock extends Model
{
    public $timestamps = false;

    protected $table = 'workforce_dsn_submission_locks';

    protected $fillable = [
        'declaration_id',
        'locked_at',
        'expires_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
