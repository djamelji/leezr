<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentSubject;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;

/**
 * ADR-757 (DMS M1) — politique d'accès documentaire EFFECTIVE côté serveur. Le simple droit
 * `documents.view` NE donne PAS accès aux pièces hautement confidentielles d'autrui (bulletins,
 * pièces d'identité, coordonnées bancaires, casier). La décision combine :
 *   tenant · sujet · type · classification · permission · relation à la personne · opération.
 *
 * Règle centrale : chacun accède à SES propres documents (y compris son bulletin) ; l'accès aux
 * documents d'autrui exige `documents.view`, et pour les pièces HAUTEMENT confidentielles un droit
 * élevé (`documents.view_confidential` ou `documents.manage`). S'applique aux métadonnées, listes,
 * aperçus, téléchargements ET versions historiques (même classification que le document courant).
 */
class DocumentAccessPolicy
{
    public const OP_VIEW = 'view';          // métadonnées / liste
    public const OP_PREVIEW = 'preview';    // aperçu inline
    public const OP_DOWNLOAD = 'download';  // téléchargement du binaire
    public const OP_VERSIONS = 'versions';  // accès à l'historique des versions

    /**
     * Décide si l'acteur peut réaliser `$operation` sur un document de sujet `$subject` et de
     * type `$typeCode` dans `$company`.
     */
    public function canAccess(User $actor, Company $company, DocumentSubject $subject, ?string $typeCode, string $operation = self::OP_VIEW): bool
    {
        // 1. Tenant — l'acteur doit appartenir à la société (ou en être owner).
        if (! $actor->isOwnerOf($company) && ! $actor->membershipFor($company)) {
            return false;
        }

        $classification = DocumentConfidentiality::forTypeCode($typeCode);
        $highlyConfidential = $classification === DocumentConfidentiality::HIGHLY_CONFIDENTIAL;

        // 2. Relation — chacun accède à SES propres documents, quelle que soit la classification.
        if ($this->isActorSubject($actor, $company, $subject)) {
            return true;
        }

        // 3. Documents d'autrui (personne ou société) — droit de vue minimum requis.
        if (! $actor->hasCompanyPermission($company, 'documents.view')) {
            return false;
        }

        // 4. Pièces HAUTEMENT confidentielles d'autrui → droit élevé obligatoire.
        if ($highlyConfidential) {
            return $actor->hasCompanyPermission($company, 'documents.view_confidential')
                || $actor->hasCompanyPermission($company, 'documents.manage');
        }

        return true;
    }

    /** Classification d'un type de document (délègue à DocumentConfidentiality). */
    public function classificationFor(?string $typeCode): string
    {
        return DocumentConfidentiality::forTypeCode($typeCode);
    }

    /**
     * L'acteur EST-il le sujet du document ? Rapprochement EXPLICITE uniquement (jamais par
     * nom/e-mail) : user → identité directe ; employee → lien employee.user_id = acteur dans CETTE
     * société. Un document de sujet `company` n'est jamais « soi ».
     */
    private function isActorSubject(User $actor, Company $company, DocumentSubject $subject): bool
    {
        if ($subject->is(DocumentSubject::TYPE_USER)) {
            return $subject->id === $actor->id;
        }

        if ($subject->is(DocumentSubject::TYPE_EMPLOYEE)) {
            $employee = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->find($subject->id);

            return $employee !== null
                && $employee->user_id !== null
                && (int) $employee->user_id === (int) $actor->id;
        }

        return false;
    }
}
