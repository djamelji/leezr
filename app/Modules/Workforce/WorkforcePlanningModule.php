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
                // ADR-729 (M6.A) — « Planning » est une ENTRÉE DE PREMIER NIVEAU : elle ouvre directement
                // la page calendrier autonome (`company-workforce-planning`), plus l'onglet d'un autre espace.
                // Fin de la redondance « Planning → Planning » (ADR-712) : le planning PRÉVISIONNEL est distinct
                // de « Temps & présences » (pointage/réel). i18n auto via nav.company.workforce-planning.
                navItems: [
                    [
                        'key' => 'workforce-planning',
                        'title' => 'Planning',
                        'to' => ['name' => 'company-workforce-planning'],
                        'icon' => 'tabler-calendar-week',
                        'permission' => 'workforce.planning_view',
                        'surface' => 'hr',
                        'group' => 'workforce',
                        'sort' => 25,
                    ],
                ],
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
