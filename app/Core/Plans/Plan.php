<?php

namespace App\Core\Plans;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'level',
        'price_monthly', 'price_yearly',
        'is_popular', 'trial_days',
        'feature_labels', 'limits', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'is_popular' => 'boolean',
            'trial_days' => 'integer',
            'feature_labels' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function priceMonthlyDollars(): float
    {
        return $this->price_monthly / 100;
    }

    public function priceYearlyDollars(): float
    {
        return $this->price_yearly / 100;
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'plan_key', 'key');
    }
}
