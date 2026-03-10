<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCoupon extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'currency',
        'max_uses',
        'used_count',
        'max_uses_per_company',
        'applicable_plan_keys',
        'applicable_billing_cycles',
        'applicable_addon_keys',
        'addon_mode',
        'duration_months',
        'first_purchase_only',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'value' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'max_uses_per_company' => 'integer',
        'applicable_plan_keys' => 'array',
        'applicable_billing_cycles' => 'array',
        'applicable_addon_keys' => 'array',
        'duration_months' => 'integer',
        'first_purchase_only' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(BillingCouponUsage::class, 'coupon_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->startOfDay()->gt($this->expires_at->startOfDay());
    }

    public function isExhausted(): bool
    {
        return $this->max_uses && $this->used_count >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if ($this->isExhausted()) {
            return false;
        }

        if ($this->starts_at && now()->startOfDay()->lt($this->starts_at->startOfDay())) {
            return false;
        }

        return true;
    }
}
