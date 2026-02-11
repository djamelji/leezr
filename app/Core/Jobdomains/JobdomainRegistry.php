<?php

namespace App\Core\Jobdomains;

/**
 * Declarative registry of all jobdomain profiles.
 * Single source of truth for what a jobdomain provides.
 * The DB table (jobdomains) stores metadata; this class stores the profile logic.
 */
class JobdomainRegistry
{
    /**
     * All jobdomain definitions (hardcoded, not in DB).
     */
    public static function definitions(): array
    {
        return [
            'logistique' => [
                'label' => 'Logistique',
                'description' => 'Transport, fleet management, dispatch',
                'landing_route' => '/',
                'nav_profile' => 'logistique',
                'default_modules' => ['core.members', 'core.settings', 'logistics_shipments'],
            ],
        ];
    }

    /**
     * Get a single definition by key.
     */
    public static function get(string $key): ?array
    {
        return static::definitions()[$key] ?? null;
    }

    /**
     * Sync definitions to the jobdomains DB table.
     */
    public static function sync(): void
    {
        foreach (static::definitions() as $key => $definition) {
            Jobdomain::updateOrCreate(
                ['key' => $key],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                ],
            );
        }
    }
}
