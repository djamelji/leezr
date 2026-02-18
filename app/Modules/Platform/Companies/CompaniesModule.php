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
                    ['key' => 'companies', 'title' => 'Companies', 'to' => ['name' => 'platform-companies'], 'icon' => 'tabler-building', 'permission' => 'manage_companies'],
                    ['key' => 'company-users', 'title' => 'Company Users', 'to' => ['name' => 'platform-company-users'], 'icon' => 'tabler-users-group', 'permission' => 'view_company_users'],
                ],
                routeNames: ['platform-companies', 'platform-company-users'],
            ),
            permissions: [
                ['key' => 'manage_companies', 'label' => 'Manage Companies'],
                ['key' => 'view_company_users', 'label' => 'View Company Users'],
            ],
            bundles: [
                [
                    'key' => 'companies.supervision',
                    'label' => 'Company Supervision',
                    'hint' => 'Manage companies and view their users.',
                    'permissions' => ['manage_companies', 'view_company_users'],
                ],
            ],
            scope: 'platform',
            type: 'internal',
        );
    }
}
