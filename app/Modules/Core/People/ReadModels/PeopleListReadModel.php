<?php

namespace App\Modules\Core\People\ReadModels;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Modules\Core\People\PersonRef;

/**
 * ADR-754 (P2) — liste Collaborateurs UNIFIÉE. Réunit, DÉDUPLIQUÉE (une personne = une ligne),
 * les membres avec compte et les collaborateurs RH, selon les FACETTES autorisées de l'acteur :
 *   - facette ACCÈS (`members.view`)   → inclut les comptes (memberships) + colonnes compte/rôle ;
 *   - facette RH    (`workforce.view`) → inclut les fiches RH (employees) + colonnes emploi.
 * Une personne reliée (employee.user_id) apparaît UNE SEULE FOIS. Aucune fusion de table, aucun
 * champ hors-facette dans le payload. Population bornée par la company → agrégation en mémoire.
 */
class PeopleListReadModel
{
    public static function list(Company $company, User $actor, array $filters = [], int $perPage = 20, int $page = 1): array
    {
        $canAccess = $actor->hasCompanyPermission($company, 'members.view');
        // ADR-816 : l'annuaire (`members.view`) liste les comptes ; leurs DÉTAILS (e-mail de connexion, état,
        // rôle d'accès, propriétaire) restent une lecture d'administration des accès.
        $details = \App\Company\RBAC\MembershipSecurityPolicy::canSeeAccountDetails($company, $actor);
        $canHr = $actor->hasCompanyPermission($company, 'workforce.view');
        $hasWorkforce = ModuleGate::isActive($company, 'workforce');

        /** @var array<string, array> $persons  clé = "u{userId}" | "e{employeeId}" */
        $persons = [];

        // ── Facette ACCÈS : memberships (comptes) ──
        if ($canAccess) {
            $memberships = Membership::where('company_id', $company->id)
                ->with(['user:id,first_name,last_name,email,avatar,password_set_at,last_login_at', 'companyRole:id,name,is_administrative'])
                ->get();
            // ADR-858 (L5) — complétude du dossier (moteur de champs existant, ADR-168b) : donnée d'administration des
            // accès (ADR-816), jamais servie sans les détails de compte.
            $completeness = $details ? \App\Company\Fields\ReadModels\CompanyUserProfileReadModel::bulkCompleteness($company, $memberships->filter(fn ($m) => $m->user)) : [];
            foreach ($memberships as $m) {
                if (! $m->user) {
                    continue;
                }
                $row = self::rowFromMembership($m, $details);
                $c = $completeness[$m->id] ?? null;
                $row['completeness'] = $c ? ['filled' => (int) $c['filled'], 'total' => (int) $c['total'], 'complete' => (bool) $c['complete']] : null;
                $persons['u'.$m->user_id] = $row;
            }
        }

        // ── Facette RH : employees (fiches), dédupliquées via user_id ──
        if ($canHr && $hasWorkforce) {
            // ADR-767 : périmètre RH = autorité Workforce (Company pour un profil RH, sinon soi +
            // rapports directs). `workforce.view` seul ne donne plus la liste de toute l'entreprise.
            $scope = \App\Modules\Workforce\Security\WorkforceAccess::employeeScopeFor($company, $actor);
            $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)
                ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
                ->with(['department:id,name', 'jobRole:id,title', 'manager:id,first_name,last_name', 'currentContract:id,employee_id,contract_type,status'])->get(); // ADR-818 : responsable + contrat
            // ADR-859 (L6) — completeness of the HR FILE for every visible employee, with or without an account (no
            // account is ever created for it) : HR facet only ; labels and counts, never a value.
            $roleKeys = Membership::where('company_id', $company->id)->whereIn('user_id', $employees->pluck('user_id')->filter())
                ->with('companyRole:id,key')->get()->mapWithKeys(fn ($m) => [$m->user_id => $m->companyRole?->key])->all();
            $files = \App\Core\Requirements\PersonFileCompleteness::forEmployees($company, $employees, $roleKeys);
            foreach ($employees as $e) {
                $key = $e->user_id ? 'u'.$e->user_id : 'e'.$e->id;
                $persons[$key] = array_merge($persons[$key] ?? self::emptyRow(), self::rowFromEmployee($e, $canAccess && $details, $persons[$key] ?? null));
                if (isset($files[$e->id])) {
                    $f = $files[$e->id];
                    $persons[$key]['completeness'] = ['filled' => $f['filled'], 'total' => $f['total'], 'complete' => $f['complete'], 'source' => 'file',
                        'to_collect' => $f['to_collect'], 'missing' => array_slice(array_column($f['missing'], 'label'), 0, 8), 'missing_count' => count($f['missing'])];
                }
            }
        }

        $rows = array_map(fn ($r) => $r + ['next_action' => self::nextAction($r)], array_values($persons));

        // ── ADR-858 (L5) — synthèse et compteurs de filtres, calculés sur ce que l'acteur PEUT voir (périmètre déjà
        // appliqué) après la recherche : les compteurs ne révèlent rien de plus que la liste elle-même. ──
        $searched = self::applyFilters($rows, ['search' => $filters['search'] ?? null]);
        $summary = self::summary($rows, $details, $canHr && $hasWorkforce);
        $filterCounts = self::filterCounts($searched, $details, $canHr && $hasWorkforce);

        // ── Filtres ──
        $rows = self::applyFilters($rows, $filters);

        // ── Tri ──
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortDir = ($filters['sort_dir'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($rows, fn ($a, $b) => $sortDir * strcasecmp((string) ($a[$sortBy] ?? ''), (string) ($b[$sortBy] ?? '')));

        // ── Pagination ──
        $total = count($rows);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'data' => array_values($slice),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'facets' => ['access' => $canAccess, 'account_details' => $details, 'hr' => $canHr],
            'summary' => $summary,
            'filter_counts' => $filterCounts,
        ];
    }

    private static function emptyRow(): array
    {
        return ['person_ref' => null, 'name' => '', 'email' => null, 'avatar' => null,
            'account_state' => null, 'role' => null, 'employment_status' => null, 'job_role' => null, 'department' => null, 'manager' => null, 'contract_type' => null];
    }

    private static function rowFromMembership(Membership $m, bool $details = true): array
    {
        $u = $m->user;
        $state = $m->isPendingAcceptance() ? (\App\Company\Security\MembershipInvitation::isExpired($m) ? 'invitation_expired' : 'pending_acceptance') : ($m->isSuspended() ? 'suspended' : (! $u->password_set_at ? 'invited' : ($u->last_login_at ? 'connected' : 'active')));

        $row = [
            'person_ref' => (string) PersonRef::forUser($u->id),
            'user_id' => $u->id,
            'employee_id' => null,
            'name' => trim($u->first_name.' '.$u->last_name) ?: $u->email,
            'email' => $u->email,
            'avatar' => $u->avatar,
            'account_state' => $state,
            'role' => $m->companyRole ? ['id' => $m->companyRole->id, 'name' => $m->companyRole->name, 'is_administrative' => (bool) $m->companyRole->is_administrative] : null,
            'is_owner' => $m->isOwner(),
            'employment_status' => null,
            'job_role' => null,
            'department' => null,
        ];

        // ADR-816 : projection annuaire — qui est qui, sans les détails de compte.
        return $details ? $row : array_merge($row, ['email' => null, 'account_state' => null, 'role' => null, 'is_owner' => null,
            'name' => trim($u->first_name.' '.$u->last_name) ?: '—']); // jamais l'e-mail en repli du nom
    }

    /** Fusionne les colonnes RH sur une ligne (existante si personne reliée). */
    private static function rowFromEmployee(Employee $e, bool $canAccess, ?array $existing): array
    {
        // Référence canonique : la fiche RH prime quand elle existe (parcours unifié P1).
        $out = [
            'person_ref' => (string) PersonRef::forEmployee($e->id),
            'employee_id' => $e->id,
            // ADR-796.1 : une recrue non intégrée n'est pas « inactive » comme un salarié : statut distinct.
            'employment_status' => $e->isPreparatory() ? 'preparatory' : $e->status,
            'job_role' => $e->jobRole?->title,
            'department' => $e->department?->name,
            // ADR-818 (chantier B) : responsable et contrat en cours, sans ouvrir la fiche.
            'manager' => $e->manager ? trim($e->manager->first_name.' '.$e->manager->last_name) : null,
            'contract_type' => $e->currentContract?->contract_type,
            // ADR-864 — the former « Gestion RH » list (one Personnes interface) : HR facet only, as before.
            'employee_number' => $e->employee_number,
            'hire_date' => $e->hire_date?->toDateString(),
            'department_id' => $e->department_id,
            'job_role_id' => $e->job_role_id,
            'manager_id' => $e->manager_id,
        ];
        // Identité : garder celle du compte si déjà présente (facette accès), sinon fiche RH.
        if (! $existing || empty($existing['name'])) {
            $out['name'] = trim($e->first_name.' '.$e->last_name) ?: ($e->email ?? '—');
        }
        if (! $existing || $existing['email'] === null) {
            $out['email'] = $canAccess ? ($existing['email'] ?? null) : null; // e-mail de compte seulement si facette accès
        }
        $out['user_id'] = $e->user_id ?: ($existing['user_id'] ?? null);

        return $out;
    }

    private const INVITATION_STATES = ['invited', 'pending_acceptance', 'invitation_expired'];

    /**
     * ADR-858 — the next useful step for a person, from what the actor can already see on the row (never a hidden
     * fact) : an expired invitation to resend, an invitation awaiting acceptance, an onboarding to finish, a file to
     * complete. Null = nothing to do.
     */
    private static function nextAction(array $r): ?string
    {
        return match (true) {
            ($r['account_state'] ?? null) === 'invitation_expired' => 'resend_invitation',
            in_array($r['account_state'] ?? null, ['invited', 'pending_acceptance'], true) => 'awaiting_acceptance',
            ($r['employment_status'] ?? null) === 'preparatory' => 'finish_onboarding',
            ! empty($r['completeness']) && ! $r['completeness']['complete'] => 'complete_file',
            default => null,
        };
    }

    /** ADR-858 — header synthesis ; a figure the actor may not read is null (never a misleading 0). */
    private static function summary(array $rows, bool $details, bool $hr): array
    {
        $count = fn (callable $f) => count(array_filter($rows, $f));

        return [
            'total' => count($rows),
            'active' => $count(fn ($r) => ($r['employment_status'] ?? null) === 'active'
                || (($r['employment_status'] ?? null) === null && in_array($r['account_state'] ?? null, ['connected', 'active'], true))),
            'invitations' => $details ? $count(fn ($r) => in_array($r['account_state'] ?? null, self::INVITATION_STATES, true)) : null,
            'incomplete' => ($details || $hr) ? $count(fn ($r) => ! empty($r['completeness']) && ! $r['completeness']['complete']) : null, // ADR-859 : HR files count too
            'preparatory' => $hr ? $count(fn ($r) => ($r['employment_status'] ?? null) === 'preparatory') : null,
            'to_act' => $count(fn ($r) => ($r['next_action'] ?? null) !== null),
        ];
    }

    /** ADR-858 — counts shown in the filters (only the dimensions the actor may filter on). */
    private static function filterCounts(array $rows, bool $details, bool $hr): array
    {
        $by = function (string $key) use ($rows) {
            $out = [];
            foreach ($rows as $r) {
                if (($r[$key] ?? null) !== null && $r[$key] !== '') {
                    $out[$r[$key]] = ($out[$r[$key]] ?? 0) + 1;
                }
            }
            ksort($out);

            return $out;
        };

        return [
            'access_state' => $details ? $by('account_state') : null,
            'employment_status' => $hr ? $by('employment_status') : null,
            'department' => $hr ? $by('department') : null,
            'job_role' => $hr ? $by('job_role') : null,
            'contract_type' => $hr ? $by('contract_type') : null, // ADR-864
            'manager' => $hr ? $by('manager') : null,
        ];
    }

    private static function applyFilters(array $rows, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower((string) $r['name']), $needle) || str_contains(mb_strtolower((string) ($r['email'] ?? '')), $needle)
                || str_contains(mb_strtolower((string) ($r['employee_number'] ?? '')), $needle)); // ADR-864 : matricule (HR rows only)
        }
        if (! empty($filters['employment_status'])) {
            $rows = array_filter($rows, fn ($r) => $r['employment_status'] === $filters['employment_status']);
        }
        if (! empty($filters['access_state'])) {
            $rows = array_filter($rows, fn ($r) => $r['account_state'] === $filters['access_state']);
        }
        if (! empty($filters['department'])) {
            $rows = array_filter($rows, fn ($r) => $r['department'] === $filters['department']);
        }
        if (! empty($filters['job_role'])) {
            $rows = array_filter($rows, fn ($r) => ($r['job_role'] ?? null) === $filters['job_role']);
        }
        if (! empty($filters['contract_type'])) { // ADR-864
            $rows = array_filter($rows, fn ($r) => ($r['contract_type'] ?? null) === $filters['contract_type']);
        }
        if (! empty($filters['manager'])) {
            $rows = array_filter($rows, fn ($r) => ($r['manager'] ?? null) === $filters['manager']);
        }
        if (! empty($filters['attention'])) {
            $rows = array_filter($rows, fn ($r) => match ($filters['attention']) {
                'incomplete' => isset($r['completeness']) && $r['completeness'] && ! $r['completeness']['complete'],
                'invitations' => in_array($r['account_state'] ?? null, self::INVITATION_STATES, true),
                'preparatory' => ($r['employment_status'] ?? null) === 'preparatory',
                'to_act' => ($r['next_action'] ?? null) !== null,
                default => true,
            });
        }
        if (! empty($filters['role_id'])) {
            $rid = (int) $filters['role_id'];
            $rows = array_filter($rows, fn ($r) => ($r['role']['id'] ?? null) === $rid);
        }

        return array_values($rows);
    }
}
