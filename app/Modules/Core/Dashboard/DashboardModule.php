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
                    // ADR-818 (chantier D) : UN seul accueil (/home) — blocs Moi / À traiter / Entreprise selon les
                    // permissions effectives. `/dashboard` reste un alias ; l'ancienne entrée « Accueil personnel » de
                    // l'avatar (doublon pour un gestionnaire salarié) est retirée : son contenu est le bloc « Moi ».
                    [
                        'key' => 'dashboard',
                        'title' => 'Dashboard',
                        'to' => ['name' => 'home'],
                        'icon' => 'tabler-smart-home',
                        'group' => 'home', 'sort' => 10,
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
