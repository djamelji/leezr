<?php

namespace App\Modules\Core\Settings;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class SettingsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.settings',
            name: 'Company Settings',
            description: 'Company name and configuration',
            surface: 'structure',
            sortOrder: 20,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'settings', 'title' => 'Settings', 'to' => ['name' => 'company-settings'], 'icon' => 'tabler-building', 'permission' => 'settings.view', 'surface' => 'structure'],
                ],
                routeNames: ['company-settings'],
                middlewareKey: 'core.settings',
            ),
            permissions: [
                ['key' => 'settings.view', 'label' => 'View Settings', 'hint' => 'See company name and configuration.'],
                ['key' => 'settings.manage', 'label' => 'Manage Settings', 'is_admin' => true, 'hint' => 'Change company name, address, and configuration.'],
            ],
            bundles: [
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
            type: 'core',
        );
    }
}
