<?php

namespace App\Modules\Core\Documents;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class DocumentsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.documents',
            name: 'Documents',
            description: 'Document governance — vault, compliance, requests and lifecycle management',
            surface: 'structure',
            sortOrder: 23,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'documents', 'title' => 'Documents', 'to' => ['name' => 'company-documents-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-file-text', 'permission' => 'documents.view', 'surface' => 'structure'],
                ],
                routeNames: ['company-documents-tab'],
                middlewareKey: 'core.documents',
            ),
            permissions: [
                ['key' => 'documents.view', 'label' => 'View Documents', 'hint' => 'See company and member documents, view compliance status.'],
                ['key' => 'documents.manage', 'label' => 'Manage Documents', 'is_admin' => true, 'hint' => 'Upload, review, request and delete documents for the company and members.'],
                ['key' => 'documents.configure', 'label' => 'Configure Documents', 'is_admin' => true, 'hint' => 'Manage document types, activation settings and custom types.'],
            ],
            bundles: [
                [
                    'key' => 'documents.access',
                    'label' => 'Document Access',
                    'hint' => 'View company and member documents and compliance status.',
                    'permissions' => ['documents.view'],
                ],
                [
                    'key' => 'documents.management',
                    'label' => 'Document Management',
                    'hint' => 'Upload, review and manage documents. Configure document types.',
                    'permissions' => ['documents.manage', 'documents.configure'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
