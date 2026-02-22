<?php

namespace App\Core\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'password_set_at',
        'avatar',
    ];

    protected $guarded = ['name'];

    protected $appends = ['status', 'display_name'];

    protected $hidden = [
        'password',
        'password_set_at',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_set_at' => 'datetime',
        ];
    }

    public function getStatusAttribute(): string
    {
        return $this->password_set_at ? 'active' : 'invited';
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getAvatarAttribute($value): ?string
    {
        return $value ? Storage::disk('public')->url($value) : null;
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

    /**
     * Check if the user has a specific company permission.
     * Owner bypasses all permissions.
     */
    public function hasCompanyPermission(Company $company, string $permissionKey): bool
    {
        if ($this->isOwnerOf($company)) {
            return true;
        }

        $membership = $this->membershipFor($company);

        if (!$membership || !$membership->company_role_id) {
            return false;
        }

        return $membership->companyRole->hasPermission($permissionKey);
    }

    public function sendPasswordResetNotification($token): void
    {
        ResetPassword::createUrlUsing(fn ($notifiable, $token) => url("/reset-password?token={$token}&email=" . urlencode($notifiable->getEmailForPasswordReset())));

        $this->notify(new ResetPassword($token));
    }
}
