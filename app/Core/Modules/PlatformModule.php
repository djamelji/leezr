<?php

namespace App\Core\Modules;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;

class PlatformModule extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_enabled_globally',
        'sort_order',
        'is_listed',
        'is_sellable',
        'settings_schema',
        'notes',
        'addon_pricing',
        'display_name_override',
        'description_override',
        'min_plan_override',
        'sort_order_override',
        'compatible_jobdomains_override',
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
            'addon_pricing' => 'array',
            'settings_schema' => 'array',
            'sort_order_override' => 'integer',
            'compatible_jobdomains_override' => 'array',
        ];
    }

    /**
     * ADR-206: Derive effective pricing mode for a company context.
     *
     * Derivation rule (first match wins):
     *   core type           → 'included'
     *   internal type       → 'internal'
     *   in jobdomain defaults → 'included'
     *   addon_pricing ≠ null → 'addon'
     *   fallback            → 'contact_sales'
     */
    public function effectivePricingModeFor(Company $company): string
    {
        $manifest = ModuleRegistry::definitions()[$this->key] ?? null;

        if ($manifest?->type === 'core') {
            return 'included';
        }

        if ($manifest?->type === 'internal') {
            return 'internal';
        }

        $defaults = $company->jobdomain->default_modules ?? [];

        if (in_array($this->key, $defaults, true)) {
            return 'included';
        }

        if ($this->addon_pricing !== null) {
            return 'addon';
        }

        return 'contact_sales';
    }
}
