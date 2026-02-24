<?php

namespace App\Core\Markets;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    protected $fillable = [
        'key',
        'name',
        'currency',
        'locale',
        'timezone',
        'dial_code',
        'flag_code',
        'flag_svg',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function legalStatuses(): HasMany
    {
        return $this->hasMany(LegalStatus::class, 'market_key', 'key');
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'market_language', 'market_key', 'language_key', 'key', 'key')
            ->withTimestamps();
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'market_key', 'key');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(TranslationOverride::class, 'market_key', 'key');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
