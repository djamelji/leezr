<?php

namespace App\Core\DataExport;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-845 (Leezr 4.2 M8) — a requested full data export. Not tenant-scoped by a global scope on purpose
 * (Platform supervision reads every company) : every Company-side query filters `company_id` explicitly.
 */
class CompanyDataExport extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const IN_PROGRESS = [self::PENDING, self::RUNNING];

    protected $fillable = ['company_id', 'requested_by_user_id', 'status', 'disk', 'path', 'size_bytes', 'manifest', 'error',
        'started_at', 'finished_at', 'expires_at', 'downloaded_at', 'download_count'];

    protected $hidden = ['path', 'disk']; // the storage location never leaves the server

    protected function casts(): array
    {
        return ['manifest' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'downloaded_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->expires_at !== null && $this->expires_at->isFuture() && $this->path !== null;
    }

    /** what the UI may show */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'status' => $this->isDownloadable() || $this->status !== self::READY ? $this->status : self::EXPIRED,
            'size_bytes' => $this->size_bytes, 'requested_at' => $this->created_at?->toIso8601String(), 'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(), 'download_count' => $this->download_count,
            'requested_by' => $this->requester ? trim($this->requester->first_name.' '.$this->requester->last_name) : null,
            'files' => array_values(array_map(fn ($f) => array_intersect_key($f, array_flip(['path', 'format', 'rows', 'description'])), $this->manifest['files'] ?? [])),
            'exclusions' => $this->manifest['exclusions'] ?? [], 'errors' => $this->manifest['errors'] ?? [], 'error' => $this->status === self::FAILED ? 'generation_failed' : null,
        ];
    }
}
