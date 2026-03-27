<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['general', 'theme', 'session', 'typography', 'maintenance', 'billing', 'world', 'ai'];

    protected $casts = [
        'general' => 'array',
        'theme' => 'array',
        'session' => 'array',
        'typography' => 'array',
        'maintenance' => 'array',
        'billing' => 'array',
        'world' => 'array',
        'ai' => 'array',
    ];

    /**
     * Singleton access — always returns the single row.
     * Creates it on first call. Auto-heals if >1 row detected (seeder race).
     */
    public static function instance(): static
    {
        $first = static::query()->orderBy('id')->first();

        if (! $first) {
            return static::create(['theme' => null]);
        }

        // Auto-heal: keep first row, delete duplicates (seeder race condition)
        $count = static::query()->count();
        if ($count > 1) {
            static::query()->where('id', '>', $first->id)->delete();
        }

        return $first;
    }
}
