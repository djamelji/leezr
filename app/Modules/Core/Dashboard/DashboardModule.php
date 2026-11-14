<?php

namespace App\Modules\Core\Dashboard;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class DashboardModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.dashboard',
            name: 'Dashboard',
            description: 'Company dashboard with customizable widget grid',
            surface: 'structure',
            sortOrder: 1,
            capabilities: new Capabilities(
                navItems: [
                    [
                        'key' => 'dashboard',
                        'title' => 'Dashboard',
                        'to' => ['name' => 'dashboard'],
                        'icon' => 'tabler-smart-home',
                        'group' => 'home', 'sort' => 10,
                    ],
                    // ADR-777 : « Accueil personnel » (/home) — pour un gestionnaire AUSSI salarié, dans le
                    // menu de l'avatar ; un salarié sans gestion y arrive déjà par « Accueil ».
                    [
                        'key' => 'personal-home',
                        'title' => 'Personal home',
                        'to' => ['name' => 'home'],
                        'icon' => 'tabler-home-heart',
                        'group' => 'personal', 'sort' => 5, 'requires' => 'employment', 'tags' => ['avatar', 'with-management'],
                    ],
                ],
                routeNames: [], // ADR-149: dashboard always accessible, no module gate
            ),
            permissions: [],
            bundles: [],
            scope: 'company',
            type: 'core',
            iconRef: 'tabler-smart-home',
        );
    }
}
