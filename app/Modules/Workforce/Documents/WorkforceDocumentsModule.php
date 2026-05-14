<?php

namespace App\Modules\Workforce\Documents;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforceDocumentsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_documents',
            name: 'Workforce Documents',
            description: 'HR document templates, contract generation, and document management',
            surface: 'hr',
            sortOrder: 240,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-documents', 'title' => 'Documents RH', 'to' => ['name' => 'company-workforce-documents'], 'icon' => 'tabler-file-certificate', 'permission' => 'workforce_documents.view', 'surface' => 'hr', 'sort' => 60],
                ],
                routeNames: [],
                middlewareKey: 'workforce_documents',
            ),
            permissions: [
                ['key' => 'workforce_documents.view', 'label' => 'View HR Documents', 'hint' => 'View generated HR documents.'],
                ['key' => 'workforce_documents.generate', 'label' => 'Generate Documents', 'is_admin' => true, 'hint' => 'Generate PDF documents from templates.'],
                ['key' => 'workforce_documents.manage_templates', 'label' => 'Manage Templates', 'is_admin' => true, 'hint' => 'Create and edit document templates.'],
                ['key' => 'workforce_documents.sign', 'label' => 'Manage Signatures', 'is_admin' => true, 'hint' => 'Manage document signature status.'],
            ],
            bundles: [
                [
                    'key' => 'workforce_documents.employee',
                    'label' => 'Documents (Employee)',
                    'hint' => 'View own generated HR documents.',
                    'permissions' => ['workforce_documents.view'],
                ],
                [
                    'key' => 'workforce_documents.management',
                    'label' => 'Documents Management',
                    'hint' => 'Generate documents, manage templates, handle signatures.',
                    'permissions' => ['workforce_documents.view', 'workforce_documents.generate', 'workforce_documents.manage_templates', 'workforce_documents.sign'],
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce'],
        );
    }
}
