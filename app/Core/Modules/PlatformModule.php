<?php

namespace App\Core\Modules;

use Illuminate\Database\Eloquent\Model;

class PlatformModule extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_enabled_globally',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled_globally' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
