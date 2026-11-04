<?php

namespace App\Modules\Workforce;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforcePlanningModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_planning',
            name: 'Workforce Planning',
            description: 'Shift scheduling, work locations, and planning calendar',
            surface: 'hr',
            sortOrder: 210,
            capabilities: new Capabilities(
                // PRODUCT-UX-2 (LOT B1) — entrée de nav retirée : le planning vit dans l'espace
                // « Temps de travail » unifié (onglet Planning). Route active (deep-link + onglet).
                navItems: [],
                routeNames: [],
                middlewareKey: 'workforce_planning',
            ),
            permissions: [
                ['key' => 'workforce.planning_view', 'label' => 'View Planning', 'hint' => 'View schedules and assigned shifts.'],
                ['key' => 'workforce.planning_manage', 'label' => 'Manage Planning', 'is_admin' => true, 'hint' => 'Create, edit, and cancel shifts and templates.'],
                ['key' => 'workforce.planning_publish', 'label' => 'Publish Planning', 'is_admin' => true, 'hint' => 'Publish schedules to make shifts visible to employees.'],
                ['key' => 'workforce.coverage_manage', 'label' => 'Manage Coverage', 'is_admin' => true, 'hint' => 'Create and edit staffing coverage requirements.'],
            ],
            bundles: [
                [
                    'key' => 'workforce.planning_employee',
                    'label' => 'Planning (Employee)',
                    'hint' => 'View own schedule and assigned shifts.',
                    'permissions' => ['workforce.planning_view'],
                ],
                [
                    'key' => 'workforce.planning_management',
                    'label' => 'Planning Management',
                    'hint' => 'Create, manage, and publish shifts.',
                    'permissions' => ['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish', 'workforce.coverage_manage'],
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce'],
        );
    }
}
