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
        'pricing_mode',
        'is_listed',
        'is_sellable',
        'pricing_model',
        'pricing_metric',
        'pricing_params',
        'settings_schema',
        'notes',
        'display_name_override',
        'description_override',
        'min_plan_override',
        'sort_order_override',
        'icon_type',
        'icon_name',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled_globally' => 'boolean',
            'is_listed' => 'boolean',
            'is_sellable' => 'boolean',
            'sort_order' => 'integer',
            'pricing_params' => 'array',
            'settings_schema' => 'array',
            'sort_order_override' => 'integer',
        ];
    }
}
