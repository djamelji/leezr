<?php

namespace App\Modules\Core\People\ReadModels;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Modules\Core\People\PersonRef;

/**
 * ADR-753 (Phase 1) — fiche Collaborateur UNIFIÉE, portée par le Core Company. Agrège, pour une
 * personne (User + Membership + Employee + CompanyRole), les facettes AUTORISÉES, sans fusion de
 * table ni création de compte. Les sections Workforce (contrat/planning/temps/congés/documents)
 * RÉUTILISENT `EmployeeFileReadModel` et n'apparaissent que si une fiche RH existe ET que le
 * module est actif → aucune dépendance obligatoire à Workforce, aucun onglet vide.
 */
class PersonFileReadModel
{
    /**
     * @return array|null  null si la personne n'existe pas dans cette company (→ 404).
     */
    public static function get(Company $company, PersonRef $ref, User $actor): ?array
    {
        // ── Résolution tenant-scopée (lien user_id explicite, jamais par nom/e-mail) ──
        $hasWorkforce = ModuleGate::isActive($company, 'workforce');
        $user = null;
        $employee = null;

        if ($ref->isUser()) {
            $membership = Membership::where('company_id', $company->id)->where('user_id', $ref->id)->first();
            if (! $membership) {
                return null; // l'utilisateur n'est pas membre de cette company
            }
            $user = User::find($ref->id);
            $employee = $hasWorkforce ? Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $ref->id)->first() : null;
        } else { // employee
            if (! $hasWorkforce) {
                return null; // pas de fiche RH sans le module
            }
            $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->find($ref->id);
            if (! $employee) {
                return null;
            }
            $user = $employee->user_id ? User::find($employee->user_id) : null;
        }

        $membership = $user ? Membership::where('company_id', $company->id)->where('user_id', $user->id)->with('companyRole.permissions:id,key')->first() : null;

        // Référence canonique : la fiche RH prime quand elle existe (parcours unifié).
        $canonical = $employee ? PersonRef::forEmployee($employee->id) : ($user ? PersonRef::forUser($user->id) : $ref);

        // ── Capacités de l'acteur PAR FACETTE (ADR-754, P2). L'ACCÈS (compte/rôle/permissions) et
        // les DONNÉES RH (emploi/contrat/temps/congés) sont deux facettes indépendantes : un RH
        // (`workforce.view`) voit l'emploi sans l'accès ; un admin des accès (`members.view`) gère
        // les comptes sans les données RH. Gating serveur strict, jamais d'élargissement implicite.
        $can = [
            'view_access' => $actor->hasCompanyPermission($company, 'members.view'),       // facette ACCÈS
            'view_hr' => $actor->hasCompanyPermission($company, 'workforce.view')          // facette RH
                && self::employeeInScope($company, $actor, $employee),
            'manage_access' => $actor->hasCompanyPermission($company, 'members.invite') || $actor->hasCompanyPermission($company, 'members.manage'),
            'change_role' => $actor->hasCompanyPermission($company, 'members.manage'),
            'edit_hr' => $actor->hasCompanyPermission($company, 'workforce.manage') && self::employeeInScope($company, $actor, $employee),
            'view_compensation' => $actor->hasCompanyPermission($company, 'workforce.compensation_read'),
            'view_history' => $actor->hasCompanyPermission($company, 'audit.view'),
        ];

        // ADR-767 : l'acteur doit détenir AU MOINS UNE facette SUR CETTE PERSONNE. Une fiche RH hors
        // périmètre (ni soi, ni rapport direct, ni profil RH) n'est pas consultable par `workforce.view`
        // seul ; elle ne reste visible que par sa facette ACCÈS (compte), sans aucune donnée RH.
        if (! $can['view_hr'] && ! ($can['view_access'] && $membership)) {
            return null;
        }

        // ── Identité : User prioritaire (compte), repli sur la fiche RH. Les champs d'origine RH
        // (téléphone, e-mail de contact RH) ne sont exposés qu'avec la facette RH. ──
        $identity = self::identity($user, $employee, $can['view_hr']);

        // ── Accès & autorisations ──
        $account = self::account($company, $user, $membership, $employee, $can['view_access']);

        // ── Emploi (réutilise EmployeeFileReadModel — gated module ET facette RH `workforce.view`) ──
        $employment = ($employee && $hasWorkforce && $can['view_hr'])
            ? \App\Modules\Workforce\ReadModels\EmployeeFileReadModel::overview($company, $employee, $can['view_compensation'])
            : null;

        // ── Onglets disponibles (module + permission + existence des données) ──
        $sections = ['overview', 'personal'];
        if ($can['view_access']) {
            $sections[] = 'access';
        }
        if ($employment) {
            $sections[] = 'employment'; // emploi & contrat
            foreach (['planning', 'time', 'leave'] as $s) {
                if (($employment[$s] ?? null) !== null || in_array($s, $employment['available_sections'] ?? [], true)) {
                    $sections[] = $s;
                }
            }
        }
        // Documents : RH (si fiche + module) OU cœur (si module documents + droit).
        $hasHrDocs = $employment && ($employment['documents'] ?? null) !== null;
        $hasCoreDocs = ModuleGate::isActive($company, 'core.documents') && $actor->hasCompanyPermission($company, 'documents.view');
        if ($hasHrDocs || $hasCoreDocs) {
            $sections[] = 'documents';
        }
        // Historique : UNIQUEMENT si des traces d'audit réelles existent ET droit audit (sinon masqué).
        if ($can['view_history'] && self::hasHistory($company, $user, $employee)) {
            $sections[] = 'history';
        }

        return [
            'person_ref' => (string) $canonical,
            'user_id' => $user?->id,
            'employee_id' => $employee?->id,
            'membership_id' => $membership?->id,
            'identity' => $identity,
            'account' => $account,
            'employment' => $employment,
            'available_sections' => array_values(array_unique($sections)),
            'can' => $can,
        ];
    }

    /** Périmètre RH (autorité Workforce) : vrai sans fiche RH (rien à restreindre). */
    private static function employeeInScope(Company $company, User $actor, ?Employee $employee): bool
    {
        if (! $employee) {
            return true;
        }
        $scope = \App\Modules\Workforce\Security\WorkforceAccess::employeeScopeFor($company, $actor);

        return $scope === null || in_array($employee->id, $scope, true);
    }

    private static function identity(?User $user, ?Employee $employee, bool $canHr): array
    {
        $first = $user?->first_name ?: $employee?->first_name;
        $last = $user?->last_name ?: $employee?->last_name;

        return [
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim(($first ?? '').' '.($last ?? '')) ?: ($user?->email ?? $employee?->email ?? '—'),
            'login_email' => $user?->email,                        // identité de connexion (User)
            'contact_email' => $canHr ? $employee?->email : null,  // e-mail RH (contact) — facette RH
            'phone' => $canHr ? $employee?->phone : null,          // téléphone RH — facette RH
            'avatar' => $user?->avatar,
        ];
    }

    private static function account(Company $company, ?User $user, ?Membership $membership, ?Employee $employee, bool $canViewAccess): array
    {
        $state = 'none';
        if ($user && $membership) {
            if ($membership->isSuspended()) {
                $state = 'suspended';
            } elseif (! $user->password_set_at) {
                $state = 'invited';
            } else {
                $state = $user->last_login_at ? 'connected' : 'active';
            }
        }

        $role = $membership?->companyRole;

        return [
            'state' => $state,
            'has_access' => $membership && ! $membership->isSuspended(),
            'is_owner' => (bool) $membership?->isOwner(),
            'is_admin' => (bool) $membership?->isAdmin(),
            // Rôle + permissions EFFECTIVES — uniquement si l'acteur peut consulter l'accès.
            'role' => ($canViewAccess && $role) ? ['id' => $role->id, 'name' => $role->name, 'is_administrative' => (bool) $role->is_administrative] : null,
            'effective_permissions' => ($canViewAccess && $role) ? $role->permissions->pluck('key')->values()->all() : null,
            // E-mail employé = cible de l'invitation quand la fiche RH n'a pas encore de compte.
            'grant_email' => $employee && ! $user ? $employee->email : null,
        ];
    }

    /** Vrai s'il existe de vraies traces d'audit pour cette personne (sinon on masque l'onglet). */
    private static function hasHistory(Company $company, ?User $user, ?Employee $employee): bool
    {
        if (! $user && ! $employee) {
            return false;
        }
        $targets = [];
        if ($user) {
            $targets[] = ['user', (string) $user->id];
        }
        if ($employee) {
            $targets[] = ['employee', (string) $employee->id];
            $targets[] = ['employee_access', (string) $employee->id];
        }

        return CompanyAuditLog::where('company_id', $company->id)
            ->where(function ($w) use ($targets) {
                foreach ($targets as [$type, $id]) {
                    $w->orWhere(fn ($x) => $x->where('target_type', $type)->where('target_id', $id));
                }
            })
            ->exists();
    }
}
