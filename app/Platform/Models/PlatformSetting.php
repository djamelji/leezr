<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['theme', 'session', 'typography', 'maintenance'];

    protected $casts = [
        'theme' => 'array',
        'session' => 'array',
        'typography' => 'array',
        'maintenance' => 'array',
    ];

    /**
     * Singleton access — always returns the single row.
     * Creates it on first call. Fails fast if >1 row detected.
     */
    public static function instance(): static
    {
        $count = static::query()->count();

        if ($count > 1) {
            throw new \RuntimeException(
                "PlatformSetting singleton violated: {$count} rows found. Expected exactly 1."
            );
        }

        return static::query()->first() ?? static::create(['theme' => null]);
    }
}
