<?php

namespace App\Modules\Workforce;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforceLeaveModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_leave',
            name: 'Workforce Leave',
            description: 'Leave requests, ledger, balances, and accrual',
            surface: 'hr',
            sortOrder: 220,
            capabilities: new Capabilities(
                navItems: [
                    // E2 : « Congés & absences » est la vue de MANAGEMENT des absences. L'index
                    // /workforce/leaves exige leave_request (garde de route) ET une capacité de vue globale
                    // (leave_view_all OU leave_approve) pour lister sans filtre. Les salariés demandent leurs
                    // congés depuis « Mon espace » (/workforce/me), pas ici → pas de menu management pour eux.
                    ['key' => 'workforce-leave', 'title' => 'Leave', 'to' => ['name' => 'company-workforce-leave'], 'icon' => 'tabler-beach', 'permission' => 'workforce.leave_request', 'anyPermission' => ['workforce.leave_view_all', 'workforce.leave_approve'], 'surface' => 'hr', 'group' => 'time', 'sort' => 30],
                    // ADR-781 — Settings hub entry : types of absence (same guards as the page + leave_manage).
                    ['key' => 'settings-leave-types', 'title' => 'Absence types', 'to' => ['name' => 'company-workforce-leave', 'query' => ['tab' => 'config']], 'icon' => 'tabler-beach', 'permission' => 'workforce.leave_request', 'allPermissions' => ['workforce.leave_manage'], 'anyPermission' => ['workforce.leave_view_all', 'workforce.leave_approve'], 'surface' => 'hr', 'group' => 'settings', 'sort' => 55, 'settings' => ['section' => 'work', 'level' => 'regular']],
                ],
                routeNames: [],
                middlewareKey: 'workforce_leave',
            ),
            permissions: [
                ['key' => 'workforce.leave_request', 'label' => 'Request Leave', 'hint' => 'Submit leave requests.'],
                ['key' => 'workforce.leave_approve', 'label' => 'Approve Leave', 'is_admin' => true, 'hint' => 'Approve or reject leave requests.'],
                ['key' => 'workforce.leave_view_all', 'label' => 'View All Leave', 'is_admin' => true, 'hint' => 'View all employee leave requests and balances.'],
                ['key' => 'workforce.leave_manage', 'label' => 'Manage Leave Types', 'is_admin' => true, 'hint' => 'Create, edit, and disable leave types.'],
                ['key' => 'workforce.leave_adjust', 'inert' => true /* ADR-807 : guards nothing (ADR-800/806) — kept, see ADR-807 §2 */, 'label' => 'Adjust Balances', 'is_admin' => true, 'hint' => 'Manually adjust leave balances via ledger.'],
            ],
            bundles: [
                [
                    'key' => 'workforce.leave_employee',
                    'label' => 'Leave (Employee)',
                    'hint' => 'Request leave and view own balances.',
                    'scope' => 'own', // ADR-804 : ses propres demandes et soldes
                    'permissions' => ['workforce.leave_request'],
                ],
                [
                    'key' => 'workforce.leave_management',
                    'label' => 'Leave Management',
                    'hint' => 'Approve requests, view all balances, manage leave types.',
                    'permissions' => ['workforce.leave_request', 'workforce.leave_approve', 'workforce.leave_view_all', 'workforce.leave_manage'],
                    'is_admin' => true, // ADR-804 : contient des permissions d'administration (aligné sur le serveur)
                ],
                [
                    'key' => 'workforce.leave_adjustment',
                    'label' => 'Leave Adjustment',
                    'hint' => 'Manually adjust employee leave balances.',
                    'permissions' => ['workforce.leave_adjust'],
                    'is_admin' => true,
                    'unwired' => true, // ADR-804 : aucune fonction ne l'utilise encore (ADR-800) — mode avancé seulement
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce'],
            // ADR-804 — pilote de l'assistant commun : Accès → Types de congés (récapitulatif) → Confirmation.
            setup: [
                'steps' => ['access', 'leave_types'],
                'readiness' => \App\Modules\Workforce\Leave\LeaveSetupReadiness::class,
            ],
        );
    }
}
