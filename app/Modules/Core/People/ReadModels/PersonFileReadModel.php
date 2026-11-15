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
            // ADR-770 : la facette RH porte sur une FICHE RH. Sans fiche, `workforce.view` ne donne aucun
            // droit sur la personne (un manager ou un RH sans facette ACCÈS ouvrait la fiche — nom, e-mail
            // de connexion, état du compte — de n'importe quel compte sans fiche salarié).
            'view_hr' => $employee !== null && $actor->hasCompanyPermission($company, 'workforce.view')
                && self::employeeInScope($company, $actor, $employee),
            'manage_access' => $actor->hasCompanyPermission($company, 'members.invite') || $actor->hasCompanyPermission($company, 'members.manage'),
            'change_role' => $actor->hasCompanyPermission($company, 'members.manage'),
            'edit_hr' => $employee !== null && $actor->hasCompanyPermission($company, 'workforce.manage') && self::employeeInScope($company, $actor, $employee),
            'view_compensation' => $actor->hasCompanyPermission($company, 'workforce.compensation_read'),
            'view_history' => $actor->hasCompanyPermission($company, 'audit.view'),
        ];

        // ADR-767 : l'acteur doit détenir AU MOINS UNE facette SUR CETTE PERSONNE. Une fiche RH hors
        // périmètre (ni soi, ni rapport direct, ni profil RH) n'est pas consultable par `workforce.view`
        // seul ; elle ne reste visible que par sa facette ACCÈS (compte), sans aucune donnée RH.
        if (! $can['view_hr'] && ! ($can['view_access'] && $membership)) {
            return null;
        }

        // ── ADR-768 (M7 LOT 2) — capacités d'ACTION de la fiche, calculées serveur à partir des MÊMES
        // autorités que les endpoints (aucune n'est une permission de navigation). L'interface n'affiche
        // une action que si elle est vraie ici ; l'endpoint la revérifie de toute façon.
        $has = fn (string $p) => $actor->hasCompanyPermission($company, $p);
        $isSelf = $user && (int) $user->id === (int) $actor->id;
        $protected = $membership && ($membership->isOwner() || $isSelf
            || \App\Company\RBAC\MembershipSecurityPolicy::isLastActiveAdmin($company, $membership));
        $inScope = self::employeeInScope($company, $actor, $employee);
        $shared = $user ? \App\Company\Security\GlobalIdentityPolicy::isShared($company->id, $user) : false;
        $can += [
            'edit_identity' => (bool) $membership && $has('members.manage'),
            'edit_global_name' => (bool) $membership && $has('members.manage') && ! $shared,
            'manage_credentials' => (bool) $membership && $has('members.credentials') && ! $membership->isOwner() && ! $isSelf,
            'set_password' => (bool) $membership && $has('members.credentials') && ! $membership->isOwner() && ! $isSelf && ! $shared,
            'suspend_access' => (bool) $membership && $has('members.manage') && ! $protected,
            'remove_access' => (bool) $membership && $has('members.manage') && ! $protected,
            'invite' => $has('members.invite'),
            'manage_contracts' => (bool) $employee && $hasWorkforce && $can['view_hr'] && $has('workforce.contracts'),
            'manage_compensation' => (bool) $employee && $hasWorkforce && $can['view_hr'] && $has('workforce.compensation_manage'),
            'generate_contract' => (bool) $employee && $hasWorkforce && $can['view_hr'] && $has('workforce_contract.generate'),
            'view_signatures' => (bool) $employee && $hasWorkforce && $can['view_hr'] && $has('workforce_signature.view'),
            'view_payslips' => self::canViewPayslips($company, $actor, $user, $employee),
            'create_hr_record' => $hasWorkforce && ! $employee && (bool) $membership && $has('workforce.manage') && $has('members.view'),
            'link_account' => $hasWorkforce && (bool) $employee && ! $user && $inScope && $has('workforce.manage') && $has('members.manage'),
        ];
        // La rémunération n'est lisible que dans le périmètre RH de l'acteur (jamais hors équipe).
        $can['view_compensation'] = $can['view_compensation'] && $can['view_hr'];

        // ── Identité : User prioritaire (compte), repli sur la fiche RH. Les champs d'origine RH
        // (téléphone, e-mail de contact RH) ne sont exposés qu'avec la facette RH. ──
        $identity = self::identity($user, $employee, $can['view_hr'], $can['view_access']);

        // ── Accès & autorisations ──
        $account = self::account($company, $user, $membership, $employee, $can['view_access'], $actor->hasCompanyPermission($company, 'roles.view'));

        // ── Emploi (réutilise EmployeeFileReadModel — gated module ET facette RH `workforce.view`) ──
        $employment = ($employee && $hasWorkforce && $can['view_hr'])
            ? \App\Modules\Workforce\ReadModels\EmployeeFileReadModel::overview($company, $employee, $can['view_compensation'], $can['view_access'])
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
        // Bulletins : autorité paie (DocumentAccessPolicy) — jamais `documents.view` ni `members.view`.
        if ($can['view_payslips']) {
            $sections[] = 'payslips';
        }
        // Historique : UNIQUEMENT si des traces d'audit réelles existent ET droit audit (sinon masqué).
        if ($can['view_history'] && self::hasHistory($company, $user, $employee)) {
            $sections[] = 'history';
        }

        return [
            'person_ref' => (string) $canonical,
            'user_id' => $user?->id,
            'employee_id' => $employee?->id,
            'membership_id' => $can['view_access'] ? $membership?->id : null, // ADR-771 : le rattachement est une donnée de compte
            'identity' => $identity,
            'account' => $account,
            'employment' => $employment,
            'identity_shared' => $shared && $can['view_access'],
            'available_sections' => array_values(array_unique($sections)),
            'can' => $can,
        ];
    }

    /** Bulletins d'AUTRUI : même autorité que l'endpoint (`DocumentAccessPolicy`, catégorie PAYROLL). */
    private static function canViewPayslips(Company $company, User $actor, ?User $user, ?Employee $employee): bool
    {
        if (! ModuleGate::isActive($company, 'core.documents')) {
            return false;
        }
        $subject = \App\Core\Documents\Services\PersonSubjectResolver::resolve($company, $user, $employee)['canonical'] ?? null;

        return $subject !== null && app(\App\Core\Documents\Services\DocumentAccessPolicy::class)
            ->canAccess($actor, $company, $subject, 'payslip', \App\Core\Documents\Services\DocumentAccessPolicy::OP_VIEW_METADATA);
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

    /**
     * ADR-771 — deux identités distinctes :
     *   - identité RH (nom porté par la fiche, coordonnées professionnelles) → facette RH ;
     *   - identité de CONNEXION (e-mail et nom du compte) → facette comptes.
     * Un acteur RH sans facette comptes ne voit que l'identité RH ; un gestionnaire de comptes sans
     * facette RH ne voit que l'identité du compte.
     */
    private static function identity(?User $user, ?Employee $employee, bool $canHr, bool $canViewAccess): array
    {
        $account = $canViewAccess ? $user : null;
        $record = $canHr ? $employee : null;
        // Le nom du compte prime quand il est visible (parcours unifié) ; sinon le nom de la fiche RH.
        $first = $account?->first_name ?: ($record?->first_name ?: $account?->first_name);
        $last = $account?->last_name ?: ($record?->last_name ?: $account?->last_name);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim(($first ?? '').' '.($last ?? '')) ?: ($account?->email ?? $record?->email ?? '—'),
            'login_email' => $account?->email,                     // identité de connexion — facette comptes
            'contact_email' => $record?->email,                    // e-mail professionnel (fiche RH) — facette RH
            'phone' => $record?->phone,                            // téléphone (fiche RH) — facette RH
            'avatar' => $user?->avatar,
        ];
    }

    private static function account(Company $company, ?User $user, ?Membership $membership, ?Employee $employee, bool $canViewAccess, bool $canViewRoles = false): array
    {
        $state = 'none';
        if ($user && $membership) {
            if ($membership->isPendingAcceptance()) {
                // M7 LOT 2 : invitation non encore acceptée par la personne ; M7 LOT 3 : expirée passé l'échéance.
                $state = \App\Company\Security\MembershipInvitation::isExpired($membership) ? 'invitation_expired' : 'pending_acceptance';
            } elseif ($membership->isSuspended()) {
                $state = 'suspended';
            } elseif (! $user->password_set_at) {
                $state = 'invited';
            } else {
                $state = $user->last_login_at ? 'connected' : 'active';
            }
        }

        $role = $membership?->companyRole;

        // ADR-771 : le statut du rattachement (actif, suspendu, invité, en attente), le rôle, les
        // drapeaux administratifs et l'état d'authentification appartiennent à la facette comptes.
        // Sans elle, seule subsiste l'information portée par la fiche RH : un compte y est lié.
        if (! $canViewAccess) {
            return [
                'state' => null, 'invitation' => null, 'has_access' => null, 'is_owner' => null, 'is_admin' => null,
                'role' => null, 'effective_permissions' => null,
                'has_account' => (bool) $user,
                'grant_email' => null,
            ];
        }

        return [
            'state' => $state,
            'invitation' => \App\Company\Security\MembershipInvitation::summary($membership),
            'has_access' => $membership && ! $membership->isSuspended(),
            'is_owner' => (bool) $membership?->isOwner(),
            'is_admin' => (bool) $membership?->isAdmin(),
            'role' => $role ? ['id' => $role->id, 'name' => $role->name, 'is_administrative' => (bool) $role->is_administrative] : null,
            // ADR-786 : la liste des permissions d'autrui relève de la configuration des rôles (roles.view),
            // pas de l'annuaire (members.view, accordé aux rôles de terrain) — minimisation des données.
            'effective_permissions' => $role && $canViewRoles ? $role->permissions->pluck('key')->values()->all() : null,
            'has_account' => (bool) $user,
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
