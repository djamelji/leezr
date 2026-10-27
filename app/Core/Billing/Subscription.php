<?php

namespace App\Core\Billing;

use App\Core\Billing\Exceptions\InvalidSubscriptionTransition;
use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Subscription extends Model
{
    use BelongsToCompany;

    // ── States ────────────────────────────────────────────
    const STATES = [
        'pending_payment', 'pending', 'trialing', 'active',
        'past_due', 'suspended', 'cancelled', 'expired', 'rejected',
    ];

    // ── Allowed transitions (ADR-232, ADR-289: +rejected) ──
    const TRANSITIONS = [
        'pending_payment' => ['active', 'trialing'],
        'pending'         => ['active', 'expired', 'rejected'],
        'trialing'        => ['active', 'past_due', 'cancelled', 'suspended', 'expired'],
        'active'          => ['past_due', 'cancelled', 'suspended', 'expired'],
        'past_due'        => ['active', 'suspended', 'cancelled'],
        'suspended'       => ['active'],
        'cancelled'       => [],
        'expired'         => [],
        'rejected'        => [],
    ];

    // Statuses that allow is_current = 1
    const CURRENT_ALLOWED_STATUSES = ['trialing', 'active', 'past_due', 'pending_payment'];

    protected $fillable = [
        'company_id', 'plan_key', 'interval', 'status', 'provider',
        'provider_subscription_id', 'current_period_start',
        'current_period_end', 'trial_ends_at', 'cancel_at_period_end',
        'billing_anchor_day', 'is_current', 'metadata',
        'coupon_id', 'coupon_months_remaining',
        'plan_price_cents', // ADR-587: contracted price snapshot (cents) for this subscription's interval
        'plan_price_source', // ADR-593: provenance of the snapshot (reliability)
        'v2_effective_at', 'commercial_policy_version', // ADR-632 (M3): V2 consolidated eligibility
    ];

    /** ADR-593 — snapshot provenances that are trusted as the contracted price. */
    const RELIABLE_PRICE_SOURCES = ['subscribed', 'plan_change', 'migrated', 'reconstructed', 'admin_confirmed'];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'billing_anchor_day' => 'integer',
            'metadata' => 'array',
            'coupon_months_remaining' => 'integer',
            'plan_price_cents' => 'integer',
            'v2_effective_at' => 'datetime',
        ];
    }

    /** ADR-593 — true when the contracted price is backed by a trusted provenance (not provisional/null). */
    public function hasReliableContractedPrice(): bool
    {
        return $this->plan_price_cents !== null
            && in_array($this->plan_price_source, self::RELIABLE_PRICE_SOURCES, true);
    }

    /**
     * ADR-587 — the contracted plan price honoured by renewals, in cents. Falls back to the current
     * catalog price only for legacy rows that predate the snapshot (null), so behaviour is preserved.
     */
    public function contractedPlanPriceCents(): ?int
    {
        if ($this->plan_price_cents !== null) {
            return (int) $this->plan_price_cents;
        }
        $plan = \App\Core\Plans\Plan::where('key', $this->plan_key)->first();
        if (! $plan) {
            return null;
        }

        return (int) ($this->interval === 'yearly' ? ($plan->price_yearly ?? 0) : ($plan->price_monthly ?? 0));
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(BillingCoupon::class, 'coupon_id');
    }

    // ── Boot: state machine guard ────────────────────────

    protected static function booted(): void
    {
        // ADR-587: snapshot the contracted plan price at creation (new subscriptions use the currently
        // published price). Covers every creation path (checkout adapters, register, trial).
        static::creating(function (Subscription $subscription) {
            if ($subscription->plan_price_cents === null && $subscription->plan_key) {
                $plan = \App\Core\Plans\Plan::where('key', $subscription->plan_key)->first();
                if ($plan) {
                    $subscription->plan_price_cents = (int) (($subscription->interval ?? 'monthly') === 'yearly'
                        ? ($plan->price_yearly ?? 0)
                        : ($plan->price_monthly ?? 0));
                    // ADR-593: a NEW subscription contracts at the currently published price — reliable.
                    $subscription->plan_price_source ??= 'subscribed';
                }
            }
        });

        static::saving(function (Subscription $subscription) {
            // Transition guard: only on update when status changes
            if ($subscription->exists && $subscription->isDirty('status')) {
                static::guardTransition($subscription);
            }

            // Invariant guard: create + update
            static::guardInvariants($subscription);
        });
    }

    private static function guardTransition(Subscription $subscription): void
    {
        $oldStatus = $subscription->getOriginal('status');
        $newStatus = $subscription->status;

        $allowed = self::TRANSITIONS[$oldStatus] ?? [];

        if (! in_array($newStatus, $allowed)) {
            $message = "Invalid subscription transition: {$oldStatus} → {$newStatus} (sub #{$subscription->id}, company #{$subscription->company_id})";

            Log::channel('billing')->critical($message, [
                'subscription_id' => $subscription->id,
                'company_id' => $subscription->company_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            throw new InvalidSubscriptionTransition($message);
        }
    }

    private static function guardInvariants(Subscription $subscription): void
    {
        $status = $subscription->status;
        $isCurrent = $subscription->is_current;

        // Invariant 1: is_current=1 only for trialing/active/past_due
        if ($isCurrent === 1 && ! in_array($status, self::CURRENT_ALLOWED_STATUSES)) {
            $message = "Invariant violation: is_current=1 with status={$status} (sub #{$subscription->id}, company #{$subscription->company_id})";

            Log::channel('billing')->critical($message, [
                'subscription_id' => $subscription->id,
                'company_id' => $subscription->company_id,
                'status' => $status,
            ]);

            throw new InvalidSubscriptionTransition($message);
        }

        // Invariant 2: status must be in STATES
        if (! in_array($status, self::STATES)) {
            $message = "Unknown subscription status: {$status} (sub #{$subscription->id}, company #{$subscription->company_id})";

            Log::channel('billing')->critical($message, [
                'subscription_id' => $subscription->id,
                'company_id' => $subscription->company_id,
                'status' => $status,
            ]);

            throw new InvalidSubscriptionTransition($message);
        }
    }

    // ── Transition methods (ADR-232) ─────────────────────

    public function markActive(): void
    {
        $this->update([
            'status' => 'active',
            'is_current' => 1,
        ]);
    }

    public function markTrialing(\DateTimeInterface $trialEndsAt): void
    {
        $this->update([
            'status' => 'trialing',
            'is_current' => 1,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }

    public function markPastDue(): void
    {
        $this->update([
            'status' => 'past_due',
        ]);
    }

    public function markSuspended(): void
    {
        $this->update([
            'status' => 'suspended',
            'is_current' => null,
        ]);
    }

    public function markCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
            'is_current' => null,
        ]);
    }

    public function markExpired(): void
    {
        $this->update([
            'status' => 'expired',
            'is_current' => null,
        ]);
    }

    // ── Relations ────────────────────────────────────────

    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ── Scopes ───────────────────────────────────────────

    public function scopeIsCurrent(Builder $query): Builder
    {
        return $query->where('is_current', 1);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeTrialing(Builder $query): Builder
    {
        return $query->where('status', 'trialing');
    }

    public function scopePendingPayment(Builder $query): Builder
    {
        return $query->where('status', 'pending_payment');
    }

    /**
     * "Usable" subscriptions — active or trialing.
     * Excludes pending, pending_payment, cancelled, expired, past_due, suspended.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'trialing']);
    }

    // ── Status helpers ───────────────────────────────────

    public function isTrialing(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPendingPayment(): bool
    {
        return $this->status === 'pending_payment';
    }

    /**
     * Request-time check: a trial whose window has elapsed, regardless of
     * whether billing:expire-trials has run yet to flip status to 'expired'.
     * This is what makes restricted access scheduler-independent — a stopped
     * cron can no longer leave an expired trial with full business rights.
     */
    public function isExpiredTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }

    /**
     * Does this subscription cut off business operations (restricted access)?
     *
     * Restricted when the trial window has elapsed (request-time, see
     * isExpiredTrial) OR the subscription has reached a terminal/ended state.
     * NOT restricted for active, past_due (dunning grace handles it), a live
     * trial, or pre-activation states (pending / pending_payment / rejected)
     * which are governed by the onboarding flow, not by this gate.
     *
     * This is intentionally distinct from Company suspension
     * (company.status = 'suspended'), which is handled separately.
     */
    public function restrictsBusinessAccess(): bool
    {
        if ($this->isExpiredTrial()) {
            return true;
        }

        return in_array($this->status, ['expired', 'suspended', 'cancelled'], true);
    }
}
