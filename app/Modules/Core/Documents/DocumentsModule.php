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
                ['key' => 'documents.view', 'label' => 'View Documents', 'hint' => 'View documents, compliance dashboard, activity timeline, and download files.'],
                ['key' => 'documents.manage', 'label' => 'Manage Documents', 'is_admin' => true, 'hint' => 'Upload, review, request, delete and upload documents on behalf of members. Preview documents before approval.'],
                ['key' => 'documents.configure', 'label' => 'Configure Documents', 'is_admin' => true, 'hint' => 'Create, edit and archive document types. Configure activation, ordering, automation settings and expiration rules.'],
            ],
            bundles: [
                [
                    'key' => 'documents.access',
                    'label' => 'Document Access',
                    'hint' => 'View documents, compliance dashboard, activity timeline, and download files.',
                    'permissions' => ['documents.view'],
                ],
                [
                    'key' => 'documents.management',
                    'label' => 'Document Management',
                    'hint' => 'Upload, review, preview, upload on behalf of members. Create, edit and archive document types. Configure activation, ordering and automation.',
                    'permissions' => ['documents.manage', 'documents.configure'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
