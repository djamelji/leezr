<?php

namespace App\Modules\Platform\Settings;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class PlatformSettingsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.settings',
            name: 'Settings',
            description: 'Platform-wide settings: appearance, theme, sessions, general',
            surface: 'structure',
            sortOrder: 90,
            capabilities: new Capabilities(
                navItems: [
                    // ADR-598 LOT B: 'settings' is a collapsible parent under Configuration.
                    ['key' => 'settings', 'title' => 'Settings', 'icon' => 'tabler-settings', 'group' => 'configuration', 'sort' => 50],
                    ['key' => 'settings-general', 'title' => 'General', 'to' => ['name' => 'platform-settings-tab', 'params' => ['tab' => 'general']], 'icon' => 'tabler-adjustments', 'permission' => 'manage_theme_settings', 'parent' => 'settings', 'sort' => 10],
                ],
                routeNames: ['platform-settings-tab', 'platform-feature-flags', 'platform-account-tab'],
            ),
            permissions: [
                ['key' => 'manage_theme_settings', 'label' => 'Manage Theme Settings'],
                ['key' => 'manage_session_settings', 'label' => 'Manage Session Settings'],
                ['key' => 'manage_maintenance', 'label' => 'Manage Maintenance Mode'],
            ],
            bundles: [
                [
                    'key' => 'settings.appearance',
                    'label' => 'Appearance',
                    'hint' => 'Manage global UI theme appearance settings.',
                    'permissions' => ['manage_theme_settings'],
                ],
                [
                    'key' => 'settings.sessions',
                    'label' => 'Sessions',
                    'hint' => 'Configure session governance (timeout, keepalive, warnings).',
                    'permissions' => ['manage_session_settings'],
                ],
                [
                    'key' => 'settings.maintenance',
                    'label' => 'Maintenance',
                    'hint' => 'Control maintenance mode, IP allowlist, and public page content.',
                    'permissions' => ['manage_maintenance'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
