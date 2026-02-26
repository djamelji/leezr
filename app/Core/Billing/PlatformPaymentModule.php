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
}
