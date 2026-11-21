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
                    // ADR-834 : also the entry point of creation operators (they see only the companies they created).
                    ['key' => 'platform-companies', 'title' => 'Companies', 'to' => ['name' => 'platform-companies'], 'icon' => 'tabler-building', 'anyPermission' => ['view_companies', 'create_companies'], 'group' => 'clients', 'sort' => 10],
                ],
                routeNames: ['platform-companies', 'platform-companies-id', 'platform-companies-create'],
            ),
            permissions: [
                ['key' => 'view_companies', 'label' => 'View Companies'],
                ['key' => 'manage_companies', 'label' => 'Manage Companies'],
                ['key' => 'view_company_users', 'label' => 'View Company Users'],
                // ADR-834 : never part of an existing bundle — granted explicitly
                ['key' => 'create_companies', 'label' => 'Create & onboard companies'],
                ['key' => 'override_company_commercial_terms', 'label' => 'Grant commercial terms different from the plan (audited)'],
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
                [
                    'key' => 'companies.onboarding',
                    'label' => 'Company Creation & Onboarding',
                    'hint' => 'Create new companies, invite their administrator and follow the start of service of the companies created.',
                    'permissions' => ['create_companies'],
                ],
                [
                    'key' => 'companies.commercial_override',
                    'label' => 'Commercial Overrides',
                    'hint' => 'Grant a trial or free period different from the plan when creating a company (audited).',
                    'permissions' => ['override_company_commercial_terms'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
