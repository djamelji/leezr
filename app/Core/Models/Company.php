<?php

namespace App\Core\Models;

use App\Company\RBAC\CompanyRole;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\CompanyWallet;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\LegalStatus;
use App\Core\Markets\Market;
use App\Core\Modules\CompanyModule;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Plans\PlanRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
        'financial_freeze',
        'plan_key',
        'market_key',
        'jobdomain_key',
        'legal_status_key',
    ];

    protected function casts(): array
    {
        return [
            'financial_freeze' => 'boolean',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_key', 'key');
    }

    public function legalStatus(): BelongsTo
    {
        return $this->belongsTo(LegalStatus::class, 'legal_status_key', 'key');
    }

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

    public function roles(): HasMany
    {
        return $this->hasMany(CompanyRole::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentProfiles(): HasMany
    {
        return $this->hasMany(CompanyPaymentProfile::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(CompanyWallet::class);
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
     * ADR-167a: Jobdomain is a structural invariant — never null.
     * Throws RuntimeException if the jobdomain key is not found in the jobdomains table.
     */
    public function getJobdomainAttribute(): Jobdomain
    {
        $jobdomain = Jobdomain::where('key', $this->jobdomain_key)->first();

        if (!$jobdomain) {
            throw new \RuntimeException(
                "Jobdomain '{$this->jobdomain_key}' not found for company #{$this->id}. "
                . "Ensure jobdomains table is seeded."
            );
        }

        return $jobdomain;
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

    public function planLevel(): int
    {
        return PlanRegistry::level(CompanyEntitlements::planKey($this));
    }
}
