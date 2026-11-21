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

    public const READY = 'ready'; // complete archive

    public const PARTIAL = 'partial'; // ADR-846 : archive produced, but some data is missing (listed in manifest.missing)

    public const AWAITING_APPROVAL = 'awaiting_approval'; // ADR-846 : exceptional request, second operator not yet decided

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const IN_PROGRESS = [self::PENDING, self::RUNNING];

    public const AVAILABLE = [self::READY, self::PARTIAL];

    public const ORIGIN_OWNER = 'owner';

    public const ORIGIN_EXCEPTIONAL = 'platform_exceptional';

    protected $fillable = ['company_id', 'requested_by_user_id', 'status', 'disk', 'path', 'size_bytes', 'manifest', 'error',
        'started_at', 'finished_at', 'expires_at', 'downloaded_at', 'download_count',
        'origin', 'recipient_user_id', 'requested_by_platform_user_id', 'approved_by_platform_user_id', 'approved_at', 'justification', 'authority_check'];

    protected $hidden = ['path', 'disk']; // the storage location never leaves the server

    protected function casts(): array
    {
        return ['manifest' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'downloaded_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** the owner, or the member an exceptional export was delivered to — nobody else, never a Platform operator */
    public function isDeliveredTo(User $user, Company $company): bool
    {
        return $user->isOwnerOf($company) || ($this->recipient_user_id !== null && $this->recipient_user_id === $user->id && $user->isMemberOf($company));
    }

    public function isDownloadable(): bool
    {
        return in_array($this->status, self::AVAILABLE, true) && $this->expires_at !== null && $this->expires_at->isFuture() && $this->path !== null;
    }

    /** what the UI may show */
    public function summary(): array
    {
        return [
            'id' => $this->id, 'status' => $this->isDownloadable() || ! in_array($this->status, self::AVAILABLE, true) ? $this->status : self::EXPIRED,
            'origin' => $this->origin ?? self::ORIGIN_OWNER, 'missing' => $this->manifest['missing'] ?? [],
            'size_bytes' => $this->size_bytes, 'requested_at' => $this->created_at?->toIso8601String(), 'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(), 'download_count' => $this->download_count,
            'requested_by' => $this->requester ? trim($this->requester->first_name.' '.$this->requester->last_name) : null,
            'files' => array_values(array_map(fn ($f) => array_intersect_key($f, array_flip(['path', 'format', 'rows', 'description'])), $this->manifest['files'] ?? [])),
            'exclusions' => $this->manifest['exclusions'] ?? [], 'errors' => $this->manifest['errors'] ?? [], 'error' => $this->status === self::FAILED ? 'generation_failed' : null,
            'recipient' => $this->recipient ? trim($this->recipient->first_name.' '.$this->recipient->last_name) : null,
        ];
    }
}
