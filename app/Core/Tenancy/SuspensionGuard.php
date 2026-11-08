<?php

namespace App\Core\Tenancy;

use App\Core\Models\Company;

/**
 * RELEASE-READINESS-V1 (ADR-737) — garde d'EXÉCUTION de suspension pour les jobs MÉTIER.
 *
 * Jusqu'ici la suspension d'une Company n'était qu'une barrière HTTP (SetCompanyContext) :
 * un job métier déjà en file (automation, documents/IA, …) s'exécutait quand même si la
 * Company passait `suspended` entre le dispatch et l'exécution. Ce garde est appelé en TÊTE
 * des jobs métier tenant pour refuser leur exécution sur une Company suspendue.
 *
 * Les traitements qui DOIVENT continuer même suspendu (billing/dunning/renew/réconciliation,
 * plateforme/cross-tenant) N'APPELLENT PAS ce garde — la régularisation reste donc possible.
 */
class SuspensionGuard
{
    /**
     * La Company est-elle suspendue ? (lecture directe du statut, contexte-agnostique).
     * `Company` est le modèle RACINE tenant (pas de CompanyScope) → pas de withoutGlobalScopes nécessaire.
     */
    public static function companySuspended(?int $companyId): bool
    {
        if (! $companyId) {
            return false;
        }

        return Company::whereKey($companyId)->value('status') === 'suspended';
    }
}
