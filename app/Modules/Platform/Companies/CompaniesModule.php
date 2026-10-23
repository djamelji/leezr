<?php

namespace App\Modules\Platform\Companies;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class CompaniesModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.companies',
            name: 'Companies',
            description: 'Manage companies and their users',
            surface: 'structure',
            sortOrder: 10,
            capabilities: new Capabilities(
                navItems: [
                    // ADR-597 (LOT 3): nav item requires only read access; writes need manage_companies.
                    ['key' => 'platform-companies', 'title' => 'Companies', 'to' => ['name' => 'platform-companies'], 'icon' => 'tabler-building', 'permission' => 'view_companies', 'group' => 'clients', 'sort' => 10],
                ],
                routeNames: ['platform-companies', 'platform-companies-id'],
            ),
            permissions: [
                ['key' => 'view_companies', 'label' => 'View Companies'],
                ['key' => 'manage_companies', 'label' => 'Manage Companies'],
                ['key' => 'view_company_users', 'label' => 'View Company Users'],
            ],
            bundles: [
                [
                    'key' => 'companies.readonly',
                    'label' => 'Company Read-Only',
                    'hint' => 'View companies and their users without modifying them.',
                    'permissions' => ['view_companies', 'view_company_users'],
                ],
                [
                    'key' => 'companies.supervision',
                    'label' => 'Company Supervision',
                    'hint' => 'View and manage companies and their users.',
                    'permissions' => ['view_companies', 'manage_companies', 'view_company_users'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
