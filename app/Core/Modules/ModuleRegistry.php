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
