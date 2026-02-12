<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function membershipFor(Company $company): ?Membership
    {
        return $this->memberships()->where('company_id', $company->id)->first();
    }

    public function roleIn(Company $company): ?string
    {
        return $this->membershipFor($company)?->role;
    }

    public function isMemberOf(Company $company): bool
    {
        return $this->memberships()->where('company_id', $company->id)->exists();
    }

    public function isOwnerOf(Company $company): bool
    {
        return $this->roleIn($company) === 'owner';
    }

    public function isAdminOf(Company $company): bool
    {
        return in_array($this->roleIn($company), ['owner', 'admin']);
    }
}
