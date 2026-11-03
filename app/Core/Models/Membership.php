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
    ];

    protected $casts = [
        'suspended_at' => 'datetime',
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
