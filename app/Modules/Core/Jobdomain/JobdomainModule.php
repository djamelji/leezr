<?php

namespace App\Modules\Core\Jobdomain;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class JobdomainModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.jobdomain',
            name: 'Industry Profile',
            description: 'Company industry profile and sector configuration',
            surface: 'structure',
            sortOrder: 22,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
                middlewareKey: 'core.jobdomain',
            ),
            permissions: [
                ['key' => 'jobdomain.view', 'label' => 'View Industry', 'hint' => 'See the company industry profile.'],
                ['key' => 'jobdomain.manage', 'label' => 'Manage Industry', 'is_admin' => true, 'hint' => 'Change the company industry profile and sector.'],
            ],
            bundles: [
                [
                    'key' => 'jobdomain.info',
                    'label' => 'Industry Information',
                    'hint' => 'View the company industry profile.',
                    'permissions' => ['jobdomain.view'],
                ],
                [
                    'key' => 'jobdomain.management',
                    'label' => 'Industry Management',
                    'hint' => 'Change the company industry profile and sector.',
                    'permissions' => ['jobdomain.manage'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
