<?php

namespace App\Modules\Platform\Documents;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class DocumentsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.documents',
            name: 'Document Types',
            description: 'Manage system document type catalog, validation rules, and archival',
            surface: 'structure',
            sortOrder: 62,
            capabilities: new Capabilities(
                navItems: [
                    [
                        'key' => 'document-types',
                        'title' => 'Document Types',
                        'to' => ['name' => 'platform-documents'],
                        'icon' => 'tabler-file-text',
                        'permission' => 'manage_document_catalog',
                        'group' => 'product',
                        'sort' => 33,
                    ],
                ],
                routeNames: ['platform-documents', 'platform-documents-id'],
            ),
            permissions: [
                ['key' => 'manage_document_catalog', 'label' => 'Manage Document Type Catalog'],
            ],
            bundles: [
                [
                    'key' => 'documents.catalog',
                    'label' => 'Document Catalog',
                    'hint' => 'Manage system document types, validation rules, and archival.',
                    'permissions' => ['manage_document_catalog'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
