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
                navItems: [],
                routeNames: ['platform-documents-id', 'platform-catalog-tab'],
            ),
            permissions: [
                ['key' => 'manage_document_catalog', 'label' => 'Manage Document Type Catalog'],
                ['key' => 'view_document_catalog', 'label' => 'View the document type catalog (read only)'], // ADR-863
            ],
            bundles: [
                [
                    'key' => 'documents.catalog',
                    'label' => 'Document Catalog',
                    'hint' => 'Manage system document types, validation rules, and archival.',
                    'permissions' => ['manage_document_catalog'],
                ],
                [
                    'key' => 'documents.catalog.reader', // ADR-863
                    'label' => 'Document Catalog (read only)',
                    'hint' => 'See the document types without changing them.',
                    'permissions' => ['view_document_catalog'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
