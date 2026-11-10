<?php

namespace App\Company\RBAC;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-752 (Consolidation Company — Phase 0) — AUTORITÉ SERVEUR UNIQUE pour la sécurité des
 * attributions de rôle et des modifications d'accès d'une membership. Appelée À L'IDENTIQUE
 * par TOUS les points d'entrée (page Members, accès collaborateur Workforce) → les contrôles
 * ne peuvent plus diverger. La décision vit ICI uniquement ; aucune règle dupliquée dans les
 * contrôleurs/services.
 *
 * Invariants garantis :
 *   - Isolation tenant : un rôle d'une autre company n'est jamais attribuable.
 *   - Pas d'auto-modification de son propre rôle (ni auto-promotion, ni auto-rétrogradation).
 *   - Pas d'escalade : un acteur NON administratif ne peut JAMAIS attribuer un rôle administratif.
 *   - Protection du dernier administrateur actif (jamais rétrogradé/retiré par inadvertance).
 *
 * Le rôle `owner` est volontairement laissé aux appelants (codes HTTP historiques 403 préservés) ;
 * cette politique couvre les règles transverses manquantes, sans dupliquer le verrou owner.
 */
class MembershipSecurityPolicy
{
    /**
     * Valide l'attribution de `$newRole` à `$target` (null = nouvelle attribution/grant) par `$actor`.
     *
     * @throws ValidationException (422) si l'attribution est interdite.
     */
    public static function assertCanAssignRole(Company $company, CompanyRole $newRole, ?User $actor, ?Membership $target = null): void
    {
        // 1. Isolation tenant.
        if ((int) $newRole->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['role' => "Ce rôle n'appartient pas à cette entreprise."]);
        }

        // 2. Pas d'auto-modification de son propre rôle.
        if ($target !== null && $actor !== null && (int) $target->user_id === (int) $actor->id) {
            throw ValidationException::withMessages(['role' => 'Vous ne pouvez pas modifier votre propre rôle.']);
        }

        // 3. Anti-escalade : seul un acteur administratif (owner/admin) peut attribuer un rôle admin.
        if ($newRole->is_administrative && ! self::isAdministrativeActor($company, $actor)) {
            throw ValidationException::withMessages(['role' => "Vous n'êtes pas autorisé à attribuer un rôle administratif."]);
        }

        // 4. Ne jamais rétrograder le dernier administrateur actif vers un rôle non-admin.
        if ($target !== null && ! $newRole->is_administrative && self::isLastActiveAdmin($company, $target)) {
            throw ValidationException::withMessages(['role' => "Impossible : ce serait le dernier administrateur de l'entreprise."]);
        }
    }

    /**
     * Valide le retrait/la suspension/la révocation de l'accès d'une membership par `$actor`.
     * (Le verrou owner reste à la charge de l'appelant pour préserver ses messages/codes.)
     *
     * @throws ValidationException (422)
     */
    public static function assertCanModifyAccess(Company $company, Membership $target, User $actor): void
    {
        if ((int) $target->user_id === (int) $actor->id) {
            throw ValidationException::withMessages(['access' => 'Vous ne pouvez pas modifier votre propre accès.']);
        }
        if (self::isLastActiveAdmin($company, $target)) {
            throw ValidationException::withMessages(['access' => "Impossible : ce serait le dernier administrateur de l'entreprise."]);
        }
    }

    /** Vrai si le rôle attribué est sensible (administratif) → audit renforcé côté appelant. */
    public static function isSensitiveRole(CompanyRole $role): bool
    {
        return (bool) $role->is_administrative;
    }

    /** L'acteur est-il owner ou titulaire d'un rôle administratif dans cette company ? (null = non) */
    public static function isAdministrativeActor(Company $company, ?User $actor): bool
    {
        if ($actor === null) {
            return false;
        }
        if ($actor->isOwnerOf($company)) {
            return true;
        }

        return (bool) $actor->membershipFor($company)?->isAdmin();
    }

    /** Vrai si `$membership` est administrative ET la seule administrative ACTIVE de la company. */
    public static function isLastActiveAdmin(Company $company, Membership $membership): bool
    {
        if (! $membership->isAdmin()) {
            return false;
        }

        $otherActiveAdmins = Membership::where('company_id', $company->id)
            ->where('id', '!=', $membership->id)
            ->whereNull('suspended_at')
            ->with('companyRole:id,is_administrative')
            ->get()
            ->filter(fn (Membership $m) => $m->isAdmin())
            ->count();

        return $otherActiveAdmins === 0;
    }
}
