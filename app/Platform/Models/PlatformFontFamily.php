<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PlatformFontFamily extends Model
{
    protected $fillable = ['name', 'slug', 'source', 'is_enabled'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($family) {
            if (empty($family->slug)) {
                $family->slug = Str::slug($family->name);
            }
        });
    }

    public function fonts(): HasMany
    {
        return $this->hasMany(PlatformFont::class, 'family_id');
    }
}
