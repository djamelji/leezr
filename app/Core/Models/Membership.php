<?php

namespace App\Core\Models;

use App\Company\RBAC\CompanyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'company_role_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
