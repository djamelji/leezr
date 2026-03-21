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
                    ['key' => 'platform-supervision', 'title' => 'Supervision', 'to' => ['name' => 'platform-supervision-tab', 'params' => ['tab' => 'companies']], 'icon' => 'tabler-building', 'permission' => 'manage_companies'],
                ],
                routeNames: ['platform-supervision-tab', 'platform-companies-id'],
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
            scope: 'admin',
            type: 'internal',
        );
    }
}
