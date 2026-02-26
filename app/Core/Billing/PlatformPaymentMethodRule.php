<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlatformPaymentMethodRule extends Model
{
    protected $fillable = [
        'method_key', 'provider_key',
        'market_key', 'plan_key', 'interval',
        'priority', 'is_active', 'constraints',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'constraints' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
