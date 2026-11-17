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
                    ['key' => 'company-profile', 'title' => 'Company Profile', 'to' => ['name' => 'company-profile-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-building-community', 'permission' => 'settings.view', 'surface' => 'structure', 'group' => 'settings', 'sort' => 10, 'settings' => ['section' => 'company', 'level' => 'essential', 'status' => 'company_profile']],
                    // ADR-819 (Company 2.0) — catalogues de la société : champs et types de documents, avec leur origine.
                    ['key' => 'company-catalogs', 'title' => 'Catalogues', 'to' => ['name' => 'company-catalogs-tab', 'params' => ['tab' => 'fields']], 'icon' => 'tabler-books', 'anyPermission' => ['settings.manage', 'documents.configure', 'workforce.manage'], 'surface' => 'structure', 'group' => 'settings', 'sort' => 15, 'settings' => ['section' => 'organization', 'level' => 'essential']],
                    // ADR-769 (M7 LOT 3) — « Mon espace » : self-service Core (documents, bulletins, signatures,
                    // profil), présent pour TOUT membre, avec ou sans Workforce, quel que soit son rôle.
                    ['key' => 'my-space', 'title' => 'My Space', 'to' => ['name' => 'company-my-space-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-user-circle', 'group' => 'personal', 'sort' => 10],
                    ['key' => 'account-settings', 'title' => 'Account Settings', 'to' => ['name' => 'account-settings-tab', 'params' => ['tab' => 'account']], 'icon' => 'tabler-settings', 'group' => 'personal', 'sort' => 900, 'tags' => ['avatar']],
                ],
                routeNames: ['company-profile-tab', 'company-catalogs-tab'],
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
            scope: 'company',
            type: 'core',
        );
    }
}
