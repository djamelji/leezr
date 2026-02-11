<?php

namespace App\Core\Modules;

/**
 * Declarative registry of all platform modules and their capabilities.
 * This is the single source of truth for module definitions.
 * Modules are seeded into platform_modules via sync().
 */
class ModuleRegistry
{
    /**
     * All module definitions.
     * Each key is the module_key, value is the definition.
     */
    public static function definitions(): array
    {
        return [
            'core.members' => [
                'name' => 'Members',
                'description' => 'Manage company members and roles',
                'sort_order' => 10,
                'capabilities' => new Capabilities(
                    navItems: [
                        ['key' => 'members', 'title' => 'Members', 'to' => ['name' => 'company-members'], 'icon' => 'tabler-users'],
                    ],
                    routeNames: ['company-members'],
                    middlewareKey: 'core.members',
                ),
            ],
            'core.settings' => [
                'name' => 'Company Settings',
                'description' => 'Company name and configuration',
                'sort_order' => 20,
                'capabilities' => new Capabilities(
                    navItems: [
                        ['key' => 'settings', 'title' => 'Settings', 'to' => ['name' => 'company-settings'], 'icon' => 'tabler-building'],
                    ],
                    routeNames: ['company-settings'],
                    middlewareKey: 'core.settings',
                ),
            ],
            'logistics_shipments' => [
                'name' => 'Shipments',
                'description' => 'Manage logistics shipments with status workflow',
                'sort_order' => 100,
                'capabilities' => new Capabilities(
                    navItems: [
                        ['key' => 'shipments', 'title' => 'Shipments', 'to' => ['name' => 'company-shipments'], 'icon' => 'tabler-truck'],
                    ],
                    routeNames: ['company-shipments', 'company-shipments-create', 'company-shipments-id'],
                    middlewareKey: 'logistics_shipments',
                ),
            ],
        ];
    }

    /**
     * Get capabilities for a given module key.
     */
    public static function capabilities(string $key): ?Capabilities
    {
        $definition = static::definitions()[$key] ?? null;

        return $definition ? $definition['capabilities'] : null;
    }

    /**
     * Sync all module definitions to the platform_modules table.
     * Called from seeder or artisan command.
     */
    public static function sync(): void
    {
        foreach (static::definitions() as $key => $definition) {
            PlatformModule::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'sort_order' => $definition['sort_order'] ?? 0,
                ],
            );
        }
    }
}
