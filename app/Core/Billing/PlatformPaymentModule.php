<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlatformPaymentModule extends Model
{
    protected $fillable = [
        'provider_key', 'name', 'description',
        'is_installed', 'is_active', 'credentials',
        'health_status', 'health_checked_at',
        'config', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_installed' => 'boolean',
            'is_active' => 'boolean',
            'credentials' => 'encrypted:array',
            'config' => 'array',
            'health_checked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_installed', true);
    }

    // ── Stripe environment helpers ─────────────────────

    /**
     * Resolve the active Stripe mode ('test' or 'live').
     * Defaults to 'test' for safety.
     */
    public function getStripeMode(): string
    {
        return ($this->credentials['mode'] ?? 'test') === 'live' ? 'live' : 'test';
    }

    /**
     * Resolve the publishable key for the active mode.
     * Backward-compatible: reads mode-prefixed keys first, then flat keys.
     */
    public function getStripePublishableKey(): ?string
    {
        $creds = $this->credentials ?? [];
        $mode = $this->getStripeMode();

        return $creds["{$mode}_publishable_key"]
            ?? $creds['publishable_key']
            ?? null;
    }

    /**
     * Resolve the secret key for the active mode.
     * Backward-compatible: reads mode-prefixed keys first, then flat keys.
     */
    public function getStripeSecretKey(): ?string
    {
        $creds = $this->credentials ?? [];
        $mode = $this->getStripeMode();

        return $creds["{$mode}_secret_key"]
            ?? $creds['secret_key']
            ?? null;
    }

    /**
     * Determine the configuration status of this payment module.
     *
     * @return string 'active' | 'misconfigured' | 'disabled'
     */
    public function getConfigurationStatus(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        if ($this->provider_key !== 'stripe') {
            return empty($this->credentials) ? 'misconfigured' : 'active';
        }

        $pk = $this->getStripePublishableKey();
        $sk = $this->getStripeSecretKey();
        $mode = $this->getStripeMode();

        if (! $pk || ! $sk) {
            return 'misconfigured';
        }

        $expectedPkPrefix = "pk_{$mode}_";
        $expectedSkPrefix = "sk_{$mode}_";

        if (! str_starts_with($pk, $expectedPkPrefix) || ! str_starts_with($sk, $expectedSkPrefix)) {
            return 'misconfigured';
        }

        return 'active';
    }
}
