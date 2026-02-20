<?php

namespace App\Core\Jobdomains;

/**
 * Declarative registry of all jobdomain profiles.
 * Single source of truth for what a jobdomain provides.
 * The DB table (jobdomains) stores metadata + presets; this class seeds them.
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
                'default_fields' => [
                    ['code' => 'siret', 'required' => true, 'order' => 0],
                    ['code' => 'vat_number', 'required' => false, 'order' => 1],
                    ['code' => 'legal_form', 'required' => false, 'order' => 2],
                    ['code' => 'phone', 'required' => false, 'order' => 3],
                    ['code' => 'job_title', 'required' => false, 'order' => 4],
                ],
                'default_roles' => [
                    'manager' => [
                        'name' => 'Manager',
                        'is_administrative' => true,
                        'bundles' => [
                            'members.team_access', 'members.team_management',
                            'settings.company_info', 'settings.company_management',
                            'shipments.operations', 'shipments.administration',
                        ],
                    ],
                    'dispatcher' => [
                        'name' => 'Dispatcher',
                        'is_administrative' => true,
                        'bundles' => [
                            'members.team_access', 'members.team_management',
                            'settings.company_info',
                            'shipments.operations',
                        ],
                    ],
                    'driver' => [
                        'name' => 'Driver',
                        'bundles' => [
                            'members.team_access',
                            'settings.company_info',
                            'shipments.delivery',
                        ],
                    ],
                    'ops_manager' => [
                        'name' => 'Operations Manager',
                        'is_administrative' => true,
                        'bundles' => [
                            'members.team_access',
                            'settings.company_info',
                            'shipments.operations',
                            'shipments.administration',
                        ],
                    ],
                ],
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
     * Persists default_modules and default_fields to DB columns.
     */
    public static function sync(): void
    {
        foreach (static::definitions() as $key => $definition) {
            Jobdomain::updateOrCreate(
                ['key' => $key],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                    'default_modules' => $definition['default_modules'] ?? [],
                    'default_fields' => $definition['default_fields'] ?? [],
                    'default_roles' => $definition['default_roles'] ?? [],
                ],
            );
        }
    }
}
