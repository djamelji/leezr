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
                // ADR-766 (M7) — ESPACE DOCUMENTS UNIFIÉ : plus d'entrée de nav « Documents » RH distincte.
                // Le registre RH (docs générés + modèles + signatures) est un ONGLET de `/company/documents`.
                // La route `company-workforce-documents` reste enregistrée comme ALIAS (redirige vers l'onglet RH).
                navItems: [],
                documentSearchSources: [\App\Modules\Workforce\Documents\Search\GeneratedDocumentsSearchSource::class], // ADR-778
                routeNames: ['company-workforce-documents'],
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
                    'hint' => 'View your own HR documents and those of your direct reports.', // ADR-805 scope
                    'permissions' => ['workforce_documents.view'],
                    'scope' => 'team', // ADR-805 : soi + rapports directs (GeneratedDocumentAccess) ; tous = RH Workforce
                ],
                [
                    'key' => 'workforce_documents.management',
                    'label' => 'Documents Management',
                    'hint' => 'Generate HR documents for the people you can see. Templates belong to Contracts, signatures to Signatures.', // ADR-806 : manage_templates/sign guard nothing
                    'permissions' => ['workforce_documents.view', 'workforce_documents.generate', 'workforce_documents.manage_templates', 'workforce_documents.sign'],
                    'is_admin' => true, // ADR-805 : permissions de gestion (déjà refusées aux rôles opérationnels)
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce'],
            // ADR-805 — assistant : aucun réglage obligatoire (accès seul). Les accès SENSIBLES vivent ailleurs
            // et sont seulement montrés (RH : documents de tous + salaire ; Documents : pièces confidentielles).
            setup: ['steps' => ['access'], 'readiness' => \App\Modules\Workforce\Documents\Setup\DocumentsSetupReadiness::class,
                'related' => ['workforce.team', 'workforce.hr_management', 'documents.management']],
        );
    }
}
