<?php

namespace App\Core\Ai;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * DB model for AI provider modules (mirrors PlatformPaymentModule).
 *
 * @see \App\Core\Billing\PlatformPaymentModule
 */
class PlatformAiModule extends Model
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

    /**
     * Determine the configuration status of this AI module.
     *
     * @return string 'active' | 'misconfigured' | 'disabled'
     */
    public function getConfigurationStatus(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        if ($this->provider_key === 'null') {
            return 'active';
        }

        // For providers requiring credentials
        $creds = $this->credentials ?? [];

        return match ($this->provider_key) {
            'ollama' => ! empty($creds['host']) ? 'active' : 'misconfigured',
            'anthropic' => ! empty($creds['api_key']) ? 'active' : 'misconfigured',
            'openai' => ! empty($creds['api_key']) ? 'active' : 'misconfigured',
            default => 'active',
        };
    }
}
