<?php

namespace App\Core\Models;

use Database\Factories\UserFactory;
use App\Core\Notifications\MailLocale;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasLocalePreference
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
        'theme_preference',
        'email_identity',
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
            'last_login_at' => 'datetime',
            'email_identity' => 'array',
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

    /**
     * ADR-771 : « ce compte est-il membre de CETTE entreprise ? » est la question qui ÉTABLIT le
     * contexte tenant (SetCompanyContext l'appelle avant de le lier). Elle porte donc son périmètre
     * elle-même — compte ET entreprise explicites — au lieu de dépendre d'un contexte déjà lié :
     * en mode tenant strict, toute requête HTTP échouait ici.
     */
    private function membershipsIn(Company $company): HasMany
    {
        return $this->memberships()->withoutGlobalScopes()->where('company_id', $company->id);
    }

    public function membershipFor(Company $company): ?Membership
    {
        return $this->membershipsIn($company)->first();
    }

    public function roleIn(Company $company): ?string
    {
        return $this->membershipFor($company)?->role;
    }

    public function isMemberOf(Company $company): bool
    {
        return $this->membershipsIn($company)->exists();
    }

    public function isOwnerOf(Company $company): bool
    {
        return $this->roleIn($company) === 'owner';
    }

    public function isAdminOf(Company $company): bool
    {
        $membership = $this->membershipFor($company);

        return $membership?->isAdmin() ?? false;
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

        // PRODUCT-SECURITY-1 (ADR-688): defense in depth — a suspended membership grants
        // no permission even if some code path bypasses SetCompanyContext (jobs, read models).
        if ($membership->isSuspended()) {
            return false;
        }

        return $membership->companyRole->hasPermission($permissionKey);
    }

    public function sendPasswordResetNotification($token): void
    {
        ResetPassword::createUrlUsing(fn ($notifiable, $token) => url("/reset-password?token={$token}&email=" . urlencode($notifiable->getEmailForPasswordReset())));

        $this->notify(new ResetPassword($token));
    }

    /** ADR-813 — e-mails (and Laravel's mail layout) in the language of the recipient's company market. */
    public function preferredLocale(): string
    {
        return MailLocale::forUser($this);
    }
}
