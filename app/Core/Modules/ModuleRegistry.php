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
                        ['key' => 'members', 'title' => 'Members', 'to' => ['name' => 'company-members'], 'icon' => 'tabler-users', 'permission' => 'members.view'],
                    ],
                    routeNames: ['company-members'],
                    middlewareKey: 'core.members',
                ),
                'permissions' => [
                    ['key' => 'members.view', 'label' => 'View Members', 'hint' => 'See the team member list and profiles.'],
                    ['key' => 'members.invite', 'label' => 'Invite Members', 'hint' => 'Send invitations to new team members.'],
                    ['key' => 'members.manage', 'label' => 'Manage Members', 'is_admin' => true, 'hint' => 'Edit profiles, assign roles, and remove members.'],
                    ['key' => 'members.credentials', 'label' => 'Manage Credentials', 'is_admin' => true, 'hint' => 'Reset passwords and manage login access.'],
                ],
                'bundles' => [
                    [
                        'key' => 'members.team_access',
                        'label' => 'Team Access',
                        'hint' => 'View the team and invite new members.',
                        'permissions' => ['members.view', 'members.invite'],
                    ],
                    [
                        'key' => 'members.team_management',
                        'label' => 'Team Management',
                        'hint' => 'Edit profiles, assign roles, and manage credentials.',
                        'permissions' => ['members.manage', 'members.credentials'],
                        'is_admin' => true,
                    ],
                ],
            ],
            'core.settings' => [
                'name' => 'Company Settings',
                'description' => 'Company name and configuration',
                'sort_order' => 20,
                'capabilities' => new Capabilities(
                    navItems: [
                        ['key' => 'settings', 'title' => 'Settings', 'to' => ['name' => 'company-settings'], 'icon' => 'tabler-building', 'permission' => 'settings.view'],
                    ],
                    routeNames: ['company-settings'],
                    middlewareKey: 'core.settings',
                ),
                'permissions' => [
                    ['key' => 'settings.view', 'label' => 'View Settings', 'hint' => 'See company name and configuration.'],
                    ['key' => 'settings.manage', 'label' => 'Manage Settings', 'is_admin' => true, 'hint' => 'Change company name, address, and configuration.'],
                ],
                'bundles' => [
                    [
                        'key' => 'settings.company_info',
                        'label' => 'Company Information',
                        'hint' => 'View company name and configuration.',
                        'permissions' => ['settings.view'],
                    ],
                    [
                        'key' => 'settings.company_management',
                        'label' => 'Company Management',
                        'hint' => 'Change company name, address, and settings.',
                        'permissions' => ['settings.manage'],
                        'is_admin' => true,
                    ],
                ],
            ],
            'logistics_shipments' => [
                'name' => 'Shipments',
                'description' => 'Manage logistics shipments with status workflow',
                'sort_order' => 100,
                'capabilities' => new Capabilities(
                    navItems: [
                        ['key' => 'shipments', 'title' => 'Shipments', 'to' => ['name' => 'company-shipments'], 'icon' => 'tabler-truck', 'permission' => 'shipments.view'],
                    ],
                    routeNames: ['company-shipments', 'company-shipments-create', 'company-shipments-id'],
                    middlewareKey: 'logistics_shipments',
                ),
                'permissions' => [
                    ['key' => 'shipments.view', 'label' => 'View Shipments', 'hint' => 'See the shipments list and details.'],
                    ['key' => 'shipments.create', 'label' => 'Create Shipments', 'hint' => 'Add new shipments to the system.'],
                    ['key' => 'shipments.manage_status', 'label' => 'Manage Shipment Status', 'hint' => 'Update shipment status and workflow.'],
                    ['key' => 'shipments.manage_fields', 'label' => 'Manage Shipment Fields', 'is_admin' => true, 'hint' => 'Configure custom fields on shipments.'],
                    ['key' => 'shipments.delete', 'label' => 'Delete Shipments', 'is_admin' => true, 'hint' => 'Permanently remove shipments from the system.'],
                ],
                'bundles' => [
                    [
                        'key' => 'shipments.operations',
                        'label' => 'Shipment Operations',
                        'hint' => 'View, create, and manage shipment status.',
                        'permissions' => ['shipments.view', 'shipments.create', 'shipments.manage_status'],
                    ],
                    [
                        'key' => 'shipments.administration',
                        'label' => 'Shipment Administration',
                        'hint' => 'Configure custom fields and delete shipments.',
                        'permissions' => ['shipments.manage_fields', 'shipments.delete'],
                        'is_admin' => true,
                    ],
                ],
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
     * Resolve bundle keys to permission keys.
     * Returns a unique list of permission keys for the given bundle keys.
     */
    public static function resolveBundles(array $bundleKeys): array
    {
        $permissionKeys = [];

        foreach (static::definitions() as $modKey => $def) {
            foreach ($def['bundles'] ?? [] as $bundle) {
                if (in_array($bundle['key'], $bundleKeys, true)) {
                    $permissionKeys = array_merge($permissionKeys, $bundle['permissions']);
                }
            }
        }

        return array_unique($permissionKeys);
    }

    /**
     * All valid bundle keys across all modules.
     */
    public static function allBundleKeys(): array
    {
        $keys = [];

        foreach (static::definitions() as $modKey => $def) {
            foreach ($def['bundles'] ?? [] as $bundle) {
                $keys[] = $bundle['key'];
            }
        }

        return $keys;
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
