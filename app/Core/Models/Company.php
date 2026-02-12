<?php

namespace App\Core\Models;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\CompanyModule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function jobdomains(): BelongsToMany
    {
        return $this->belongsToMany(Jobdomain::class, 'company_jobdomain')
            ->withTimestamps();
    }

    /**
     * Convenience accessor: $company->jobdomain returns the single assigned jobdomain (or null).
     */
    public function getJobdomainAttribute(): ?Jobdomain
    {
        return $this->jobdomains->first();
    }

    public function owner(): ?User
    {
        return $this->users()->wherePivot('role', 'owner')->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
