<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentSubject;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;

/**
 * ADR-757 (M1) / ADR-758 (M1.1) — politique d'accès documentaire CONTEXTUELLE côté serveur.
 *
 * La décision combine : tenant · sujet · type · **catégorie** · permission · **relation** · opération.
 * Elle distingue les acteurs SANS coder les rôles en dur — uniquement via les permissions existantes
 * et des relations métier vérifiées (manager direct via `Employee.manager_id`) :
 *
 *  - **self** : accède à TOUS ses propres documents (toute catégorie).
 *  - **manager d'équipe** : accède aux documents STANDARD de ses subordonnés directs (relation
 *    `manager_id` vérifiée), mais JAMAIS à leurs pièces hautement confidentielles sans droit élevé.
 *  - **gestionnaire documentaire / RH** (`documents.view`) : documents STANDARD d'autrui.
 *  - **gestionnaire de paie** (`workforce.compensation_read` | `workforce.payroll_validate` |
 *    `workforce.payroll_prepare`) : bulletins (catégorie PAYROLL).
 *  - **RH sensible** (`documents.view_confidential` | `workforce.sensitive_read`) : ID/bancaire/
 *    judiciaire (catégorie IDENTITY_FINANCIAL).
 *  - **admin documents** (`documents.manage`) et **owner** : tout.
 *
 * `documents.view_confidential` N'EST PAS un passe universel : il ouvre l'identité/bancaire/judiciaire,
 * pas les bulletins (domaine paie). S'applique aux métadonnées, listes, aperçus, téléchargements et
 * versions historiques (même catégorie que le document courant).
 */
class DocumentAccessPolicy
{
    public const OP_VIEW_METADATA = 'view_metadata'; // liste / compteurs / métadonnées
    public const OP_PREVIEW = 'preview';
    public const OP_DOWNLOAD = 'download';
    public const OP_VERSIONS = 'versions';

    public function canAccess(User $actor, Company $company, DocumentSubject $subject, ?string $typeCode, string $operation = self::OP_VIEW_METADATA): bool
    {
        // 1. Tenant — appartenance obligatoire (owner = accès total).
        if ($actor->isOwnerOf($company)) {
            return true;
        }
        if (! $actor->membershipFor($company)) {
            return false;
        }

        // 2. Relation « soi » — ses propres documents, toute catégorie.
        if ($this->isActorSubject($actor, $company, $subject)) {
            return true;
        }

        // 3. Admin documents — accès à tout le périmètre documentaire.
        if ($actor->hasCompanyPermission($company, 'documents.manage')) {
            return true;
        }

        // 4. Décision par catégorie.
        $category = DocumentConfidentiality::category($typeCode);

        return match ($category) {
            DocumentConfidentiality::CAT_PAYROLL => $this->canAccessPayroll($actor, $company),
            DocumentConfidentiality::CAT_IDENTITY_FINANCIAL => $this->canAccessIdentityFinancial($actor, $company),
            default => $this->canAccessStandard($actor, $company, $subject),
        };
    }

    /** Documents standard d'autrui : droit de vue OU relation de management direct. */
    private function canAccessStandard(User $actor, Company $company, DocumentSubject $subject): bool
    {
        return $actor->hasCompanyPermission($company, 'documents.view')
            || $this->managesSubject($actor, $company, $subject);
    }

    /** Bulletins (paie) : domaine rémunération/paie OU droit dédié de téléchargement de bulletins. */
    private function canAccessPayroll(User $actor, Company $company): bool
    {
        return $actor->hasCompanyPermission($company, 'payslips.download')
            || $actor->hasCompanyPermission($company, 'workforce.compensation_read')
            || $actor->hasCompanyPermission($company, 'workforce.payroll_validate')
            || $actor->hasCompanyPermission($company, 'workforce.payroll_prepare');
    }

    /** Identité / bancaire / social / judiciaire : droit confidentiel ou RH sensible. */
    private function canAccessIdentityFinancial(User $actor, Company $company): bool
    {
        return $actor->hasCompanyPermission($company, 'documents.view_confidential')
            || $actor->hasCompanyPermission($company, 'workforce.sensitive_read');
    }

    /** Classification (niveau) d'un type. */
    public function classificationFor(?string $typeCode): string
    {
        return DocumentConfidentiality::forTypeCode($typeCode);
    }

    /**
     * L'acteur EST-il le sujet ? Rapprochement EXPLICITE uniquement (jamais nom/e-mail) :
     * user → identité directe ; employee → `employee.user_id = acteur` dans CETTE société.
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

    /**
     * L'acteur est-il le MANAGER DIRECT de l'employé sujet ? Relation vérifiée via
     * `Employee.manager_id` (un niveau), cohérente avec WorkforceAccess (congés/temps).
     */
    private function managesSubject(User $actor, Company $company, DocumentSubject $subject): bool
    {
        if (! $subject->is(DocumentSubject::TYPE_EMPLOYEE)) {
            return false;
        }

        $self = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('user_id', $actor->id)
            ->first();

        if ($self === null) {
            return false;
        }

        return Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('id', $subject->id)
            ->where('manager_id', $self->id)
            ->exists();
    }
}
