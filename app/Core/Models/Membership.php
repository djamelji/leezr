<?php

namespace App\Core\Models;

use App\Company\RBAC\CompanyRole;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'company_role_id',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
        'acceptance_pending_at',
        'invited_by',
        'invitation_delivery_status',
        'invitation_sent_at',
        'invitation_delivery_error',
        'invitation_expires_at',
        'invitation_expired_at',
    ];

    protected $casts = [
        'suspended_at' => 'datetime',
        'acceptance_pending_at' => 'datetime',
        'invitation_sent_at' => 'datetime',
        'invitation_expires_at' => 'datetime',
        'invitation_expired_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** PRODUCT-SECURITY-1 (ADR-688): access is suspended (membership kept, all data preserved). */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * M7 LOT 2: invitation of a PRE-EXISTING account not yet accepted by its owner. Such a
     * membership is also suspended (no access anywhere) and only the recipient can activate it.
     */
    public function isPendingAcceptance(): bool
    {
        return $this->acceptance_pending_at !== null;
    }

    public function companyRole(): BelongsTo
    {
        return $this->belongsTo(CompanyRole::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Is this membership administrative?
     *
     * Owner always bypasses. For all others, CompanyRole.is_administrative
     * is the sole source of truth.
     */
    public function isAdmin(): bool
    {
        if ($this->role === 'owner') {
            return true;
        }

        return (bool) $this->companyRole?->is_administrative;
    }
}
