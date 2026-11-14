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
                // ADR-766 (M7) — ESPACE DOCUMENTS UNIFIÉ : une seule entrée « Documents ». Visible pour la
                // gouvernance DMS (`documents.view`) OU le registre RH (`workforce_documents.view`) OU les
                // signatures (`workforce_signature.view`). Chaque onglet reste gated par sa permission réelle
                // (aucun élargissement d'accès : un profil ne voit que les onglets qu'il peut réellement ouvrir).
                navItems: [
                    ['key' => 'documents', 'title' => 'Documents', 'to' => ['name' => 'company-documents-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-file-text', 'anyPermission' => ['documents.view', 'workforce_documents.view', 'workforce_signature.view'], 'surface' => 'structure', 'group' => 'documents', 'sort' => 10],
                ],
                routeNames: ['company-documents-tab'],
                actions: [ // ADR-775
                    ['key' => 'documents.request', 'space' => 'documents', 'icon' => 'tabler-file-import', 'to' => ['name' => 'company-documents-tab', 'params' => ['tab' => 'requests']], 'permission' => 'documents.manage', 'home' => true, 'primary' => true, 'sort' => 10],
                    ['key' => 'documents.deposit', 'space' => 'documents', 'icon' => 'tabler-file-upload', 'to' => ['name' => 'company-documents-tab', 'params' => ['tab' => 'vault']], 'permission' => 'documents.manage', 'home' => true, 'sort' => 20],
                ],
                middlewareKey: 'core.documents',
            ),
            permissions: [
                ['key' => 'documents.view', 'label' => 'View Documents', 'hint' => 'View documents, compliance dashboard, activity timeline, and download files.'],
                ['key' => 'documents.view_confidential', 'label' => 'View Confidential Documents', 'is_admin' => true, 'hint' => "Access other people's highly-confidential documents (payslips, ID, bank details, judicial records). documents.view alone never grants this."],
                ['key' => 'documents.manage', 'label' => 'Manage Documents', 'is_admin' => true, 'hint' => 'Upload, review, request, delete and upload documents on behalf of members. Preview documents before approval.'],
                ['key' => 'documents.configure', 'label' => 'Configure Documents', 'is_admin' => true, 'hint' => 'Create, edit and archive document types. Configure activation, ordering, automation settings and expiration rules.'],
                // ADR-762 (M5): import de bulletins externes — droits DISTINCTS (jamais déduits de documents.manage ni de workforce.compensation_read).
                ['key' => 'payslips.import', 'label' => 'Import Payslips', 'is_admin' => true, 'hint' => 'Import external payslip batches and run recognition/matching.'],
                ['key' => 'payslips.review', 'label' => 'Review Payslip Matches', 'is_admin' => true, 'hint' => 'Preview, correct matches, assign to an employee, exclude a document.'],
                ['key' => 'payslips.publish', 'label' => 'Publish Payslips', 'is_admin' => true, 'hint' => 'Validate a batch and publish payslips to employee folders. Revoke a publication.'],
                ['key' => 'payslips.download', 'label' => 'Download Payslips', 'is_admin' => true, 'hint' => 'Download individual payslips (others). Never granted by documents.view alone.'],
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
                    'hint' => 'Upload, review, preview, upload on behalf of members. Access confidential pieces. Create, edit and archive document types. Configure activation, ordering and automation.',
                    'permissions' => ['documents.manage', 'documents.view_confidential', 'documents.configure'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'payslips.distribution',
                    'label' => 'Payslip Distribution',
                    'hint' => 'Import, review, publish and download external payslips (independent of the payroll generation engine).',
                    'permissions' => ['payslips.import', 'payslips.review', 'payslips.publish', 'payslips.download'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
