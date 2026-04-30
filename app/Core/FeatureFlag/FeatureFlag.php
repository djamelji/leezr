<?php

namespace App\Core\FeatureFlag;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'description', 'enabled_globally', 'company_overrides'];

    protected $casts = [
        'enabled_globally' => 'boolean',
        'company_overrides' => 'array',
    ];

    /**
     * Check if a flag is enabled for a specific company.
     * Company override takes precedence over global.
     */
    public static function isEnabled(string $key, ?int $companyId = null): bool
    {
        $flag = Cache::remember("feature_flag:{$key}", 300, function () use ($key) {
            return static::where('key', $key)->first();
        });

        if (!$flag) {
            return false;
        }

        // Company-specific override
        if ($companyId && is_array($flag->company_overrides)) {
            $override = $flag->company_overrides[(string) $companyId] ?? null;
            if ($override !== null) {
                return (bool) $override;
            }
        }

        return $flag->enabled_globally;
    }

    /**
     * Clear cache when flag changes.
     */
    protected static function booted(): void
    {
        static::saved(function (FeatureFlag $flag) {
            Cache::forget("feature_flag:{$flag->key}");
        });

        static::deleted(function (FeatureFlag $flag) {
            Cache::forget("feature_flag:{$flag->key}");
        });
    }
}
