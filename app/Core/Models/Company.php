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
use App\Core\Workforce\ConventionCollective;
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
        'siret',
        'naf_code',
        'address_street',
        'address_complement',
        'address_postal_code',
        'address_city',
        'address_insee_code',
        'address_country_code',
        'average_headcount',
        'slug',
        'status',
        'financial_freeze',
        'plan_key',
        'market_key',
        'default_convention_collective_id',
        'jobdomain_key',
        'legal_status_key',
        'onboarding_dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'financial_freeze' => 'boolean',
            'onboarding_dismissed_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_key', 'key');
    }

    public function defaultConventionCollective(): BelongsTo
    {
        return $this->belongsTo(ConventionCollective::class, 'default_convention_collective_id');
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

    /**
     * The subscription that governs business access right now. Prefers the
     * flagged current one; otherwise the most recent subscription (so a trial
     * already flipped to 'expired', is_current=null, still governs access).
     * Null when the company has no subscription yet (onboarding / implicit free).
     */
    public function governingSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->orderByRaw('CASE WHEN is_current = 1 THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Restricted access (product decision, audit 2026-10): an expired trial or a
     * terminated subscription keeps READ access + billing/payment, but blocks
     * business operations. Evaluated at request time, so a stopped scheduler can
     * never leave an expired trial fully active. Reactivation is automatic and
     * idempotent — once a valid subscription exists again this returns false with
     * no extra step. Distinct from Company suspension (isSuspended()).
     */
    public function hasBusinessAccess(): bool
    {
        $sub = $this->governingSubscription();

        return $sub === null || ! $sub->restrictsBusinessAccess();
    }

    public function planLevel(): int
    {
        return PlanRegistry::level(CompanyEntitlements::planKey($this));
    }
}
