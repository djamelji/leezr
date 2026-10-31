<?php

namespace App\Modules\Workforce\Signature;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * W-SIGNATURE-1 (ADR-651) — module « Signatures électroniques RH ».
 *
 * Module commercial INDÉPENDANT : il fait signer tout document RH émis (GeneratedDocument),
 * pas seulement les contrats. Dépend de workforce_documents (le coffre horizontal), JAMAIS de
 * workforce_contract. Addon non auto-activé. Aucune signature qualifiée sans prestataire adéquat :
 * l'adaptateur interne est un outil de DEV (preuves NON certifiées), bloqué en PROD.
 */
class WorkforceSignatureModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_signature',
            name: 'Signatures électroniques',
            description: 'Electronic signature requests for emitted HR documents — provider-agnostic, independent of Contract.',
            surface: 'hr',
            sortOrder: 260,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-signature', 'title' => 'Signatures', 'to' => ['name' => 'company-workforce-signature'], 'icon' => 'tabler-signature', 'permission' => 'workforce_signature.view', 'surface' => 'hr', 'group' => 'hr_documents', 'sort' => 75],
                ],
                routeNames: [],
                middlewareKey: 'workforce_signature',
            ),
            permissions: [
                ['key' => 'workforce_signature.view', 'label' => 'View Signatures', 'hint' => 'View signature requests and download signed documents within scope.'],
                ['key' => 'workforce_signature.request', 'label' => 'Request Signatures', 'is_admin' => true, 'hint' => 'Create a signature request on an emitted HR document.'],
                ['key' => 'workforce_signature.manage', 'label' => 'Manage Signatures', 'is_admin' => true, 'hint' => 'Cancel, remind and manage signature requests.'],
                ['key' => 'workforce_signature.view_evidence', 'label' => 'View Signature Evidence', 'is_admin' => true, 'hint' => 'View the audit trail and proofs of a signature request.'],
                ['key' => 'workforce_signature.admin', 'label' => 'Administer Signature Settings', 'is_admin' => true, 'hint' => 'Configure provider, default level, validity, reminders and signed-copy delivery.'],
            ],
            bundles: [
                [
                    'key' => 'workforce_signature.management',
                    'label' => 'Signatures Management',
                    'hint' => 'Create, manage and administer electronic signature requests.',
                    'permissions' => ['workforce_signature.view', 'workforce_signature.request', 'workforce_signature.manage', 'workforce_signature.view_evidence', 'workforce_signature.admin'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'workforce_signature.readonly',
                    'label' => 'Signatures (Read-only)',
                    'hint' => 'View signature requests and download signed documents.',
                    'permissions' => ['workforce_signature.view'],
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce', 'workforce_documents'],
            iconRef: 'tabler-signature',
        );
    }
}
