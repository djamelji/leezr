<?php

namespace App\Modules\Workforce\Contract;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * ADR-647 (W-CONTRACT-1) — module commercial INDÉPENDANT « Contrats de travail ».
 *
 * Réutilise les services HORIZONTAUX (DocumentTemplate, GeneratedDocument, DocumentVariableResolver,
 * GenerateDocumentUseCase, DocumentPdfGenerator, Core/Documents). Aucun 2ᵉ moteur PDF/modèles/stockage.
 * Non activé automatiquement pour les Companies existantes ; aucune facturation/souscription nouvelle.
 */
class WorkforceContractModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_contract',
            name: 'Contrats de travail',
            description: 'Contract templates library, employee contract document generation and archiving',
            surface: 'hr',
            sortOrder: 250,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-contract-templates', 'title' => 'Contrats de travail', 'to' => ['name' => 'company-workforce-contract-templates'], 'icon' => 'tabler-file-text', 'permission' => 'workforce_contract.view', 'surface' => 'hr', 'group' => 'hr_documents', 'sort' => 70],
                ],
                routeNames: [],
                middlewareKey: 'workforce_contract',
            ),
            permissions: [
                ['key' => 'workforce_contract.view', 'label' => 'View Contracts', 'hint' => 'View contract templates and generated contract documents.'],
                ['key' => 'workforce_contract.manage_templates', 'label' => 'Manage Contract Templates', 'is_admin' => true, 'hint' => 'Duplicate, edit, publish and archive the Company contract templates.'],
                ['key' => 'workforce_contract.generate', 'label' => 'Generate Contract Document', 'is_admin' => true, 'hint' => 'Generate a contract PDF for an employee.'],
            ],
            bundles: [
                [
                    'key' => 'workforce_contract.management',
                    'label' => 'Contracts Management',
                    'hint' => 'Manage contract templates and generate contract documents.',
                    'permissions' => ['workforce_contract.view', 'workforce_contract.manage_templates', 'workforce_contract.generate'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce', 'workforce_documents'],
            iconRef: 'tabler-file-text',
        );
    }
}
