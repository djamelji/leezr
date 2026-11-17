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
 *
 * ADR-803.1 — même autorité pour la MODIFICATION DES PERMISSIONS d'un rôle (éditeur Équipe → Rôles,
 * assistant de module, visibilité du thème, toute API directe) — politique de DÉLÉGATION :
 *   - le propriétaire délègue tout ;
 *   - un acteur administratif ne délègue (ajoute OU retire) que des permissions qu'il DÉTIENT ;
 *     `roles.manage` (le pouvoir de donner des droits) ne s'ajoute à un rôle que par le propriétaire ;
 *   - un acteur non administratif ne délègue rien ;
 *   - pas de modification de son propre rôle (ni auto-élévation ni auto-rétrogradation), pas de permission
 *     d'administration sur un rôle opérationnel, rôle `owner` protégé, isolation tenant ;
 *   - attribuer un rôle existant exige que TOUTES ses permissions soient détenues par l'acteur.
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

        // 3 bis (ADR-803.1). Délégation : on n'attribue pas un rôle plus puissant que soi.
        if ($actor !== null && ! $actor->isOwnerOf($company)) {
            $held = self::heldPermissions($company, $actor);
            $missing = array_diff($newRole->permissions()->pluck('key')->all(), $held);
            if ($missing) {
                throw ValidationException::withMessages(['role' => "Ce rôle donne des droits que vous ne détenez pas."]);
            }
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

    /** ADR-803.1 — permissions only the owner may ADD to a role. */
    public const OWNER_ONLY_DELEGATION = ['roles.manage'];

    public const VIOLATION_MESSAGES = [
        'other_company' => "Ce rôle n'appartient pas à cette entreprise.",
        'protected_role' => 'Le rôle propriétaire ne se modifie pas.',
        'operational_role' => "Un rôle opérationnel ne peut pas recevoir de droit d'administration.",
        'own_role' => 'Vous ne pouvez pas modifier les droits de votre propre rôle.',
        'beyond_your_rights' => "Vous ne pouvez pas déléguer des droits que vous n'êtes pas habilité à déléguer.",
    ];

    /** Permissions effectively held by `$actor` here (null = all : owner ; suspended / no role = none). */
    public static function heldPermissions(Company $company, User $actor): array
    {
        $m = $actor->membershipFor($company);
        if (! $m || $m->isSuspended() || ! $m->companyRole) {
            return [];
        }

        // ADR-808.1 : a right pending confirmation is not held — it can neither be used nor delegated.
        return $m->companyRole->effectivePermissionKeys();
    }

    /** Permissions `$actor` may add or remove on a role. null = everything (owner). */
    public static function delegablePermissions(Company $company, ?User $actor): ?array
    {
        if ($actor === null) {
            return [];
        }
        if ($actor->isOwnerOf($company)) {
            return null;
        }
        if (! self::isAdministrativeActor($company, $actor)) {
            return [];
        }

        return self::heldPermissions($company, $actor);
    }

    /**
     * ADR-803.1 — the ONE check for any change of a role's permissions. Returns a violation code
     * (see VIOLATION_MESSAGES) or null. `$add` / `$remove` are permission KEYS (the effective diff).
     */
    public static function rolePermissionChangeViolation(Company $company, CompanyRole $role, array $add, array $remove, ?User $actor): ?string
    {
        if ((int) $role->company_id !== (int) $company->id) {
            return 'other_company';
        }
        if (! $add && ! $remove) {
            return null;
        }
        if ($role->key === 'owner') {
            return 'protected_role';
        }
        if ($add && ! $role->is_administrative && CompanyPermission::whereIn('key', $add)->where('is_admin', true)->exists()) {
            return 'operational_role';
        }
        if ($actor !== null && $actor->isOwnerOf($company)) {
            return null;
        }
        // Same rule as role assignment (ADR-752) : no self-promotion, no self-demotion.
        if ($actor !== null && (int) $actor->membershipFor($company)?->company_role_id === (int) $role->id) {
            return 'own_role';
        }
        $delegable = self::delegablePermissions($company, $actor) ?? [];
        if (array_diff($remove, $delegable) || array_diff($add, array_diff($delegable, self::OWNER_ONLY_DELEGATION))) {
            return 'beyond_your_rights';
        }

        return null;
    }

    /** @throws ValidationException (422) */
    public static function assertCanChangeRolePermissions(Company $company, CompanyRole $role, array $add, array $remove, ?User $actor): void
    {
        if ($code = self::rolePermissionChangeViolation($company, $role, $add, $remove, $actor)) {
            $e = ValidationException::withMessages(['permissions' => self::VIOLATION_MESSAGES[$code]]);
            $e->errorBag = $code; // machine-readable reason for callers that need it

            throw $e;
        }
    }

    /** Vrai si le rôle attribué est sensible (administratif) → audit renforcé côté appelant. */
    /**
     * ADR-816 — account details of the OTHER members (login e-mail, account state, access role,
     * owner/admin flags, membership id) are an ACCESS-ADMINISTRATION read. `members.view` is the team
     * directory — granted to field roles (bundle `members.team_view`) — and only shows who is who.
     * `roles.view` (role administration) keeps the role facet it already relied on (ADR-786).
     * Owner bypass is carried by hasCompanyPermission().
     */
    public static function canSeeAccountDetails(Company $company, ?User $actor): bool
    {
        if (! $actor) {
            return false;
        }
        // Same guards as User::hasCompanyPermission() (owner bypass, suspension), with a single membership read.
        $membership = $actor->membershipFor($company);
        if ($membership?->role === 'owner') {
            return true;
        }
        if (! $membership || ! $membership->company_role_id || $membership->isSuspended()) {
            return false;
        }
        // Access administration, or role administration (who holds which role is part of it).
        foreach (['members.invite', 'members.manage', 'members.credentials', 'roles.view'] as $permission) {
            if ($membership->companyRole->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

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
