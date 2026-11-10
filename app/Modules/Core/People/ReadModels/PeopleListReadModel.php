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
        $canHr = $actor->hasCompanyPermission($company, 'workforce.view');
        $hasWorkforce = ModuleGate::isActive($company, 'workforce');

        /** @var array<string, array> $persons  clé = "u{userId}" | "e{employeeId}" */
        $persons = [];

        // ── Facette ACCÈS : memberships (comptes) ──
        if ($canAccess) {
            $memberships = Membership::where('company_id', $company->id)
                ->with(['user:id,first_name,last_name,email,avatar,password_set_at,last_login_at', 'companyRole:id,name,is_administrative'])
                ->get();
            foreach ($memberships as $m) {
                if (! $m->user) {
                    continue;
                }
                $persons['u'.$m->user_id] = self::rowFromMembership($m);
            }
        }

        // ── Facette RH : employees (fiches), dédupliquées via user_id ──
        if ($canHr && $hasWorkforce) {
            $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)
                ->with(['department:id,name', 'jobRole:id,title'])->get();
            foreach ($employees as $e) {
                $key = $e->user_id ? 'u'.$e->user_id : 'e'.$e->id;
                $persons[$key] = array_merge($persons[$key] ?? self::emptyRow(), self::rowFromEmployee($e, $canAccess, $persons[$key] ?? null));
            }
        }

        $rows = array_values($persons);

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
            'facets' => ['access' => $canAccess, 'hr' => $canHr],
        ];
    }

    private static function emptyRow(): array
    {
        return ['person_ref' => null, 'name' => '', 'email' => null, 'avatar' => null,
            'account_state' => null, 'role' => null, 'employment_status' => null, 'job_role' => null, 'department' => null];
    }

    private static function rowFromMembership(Membership $m): array
    {
        $u = $m->user;
        $state = $m->isSuspended() ? 'suspended' : (! $u->password_set_at ? 'invited' : ($u->last_login_at ? 'connected' : 'active'));

        return [
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
    }

    /** Fusionne les colonnes RH sur une ligne (existante si personne reliée). */
    private static function rowFromEmployee(Employee $e, bool $canAccess, ?array $existing): array
    {
        // Référence canonique : la fiche RH prime quand elle existe (parcours unifié P1).
        $out = [
            'person_ref' => (string) PersonRef::forEmployee($e->id),
            'employee_id' => $e->id,
            'employment_status' => $e->status,
            'job_role' => $e->jobRole?->title,
            'department' => $e->department?->name,
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

    private static function applyFilters(array $rows, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower((string) $r['name']), $needle) || str_contains(mb_strtolower((string) ($r['email'] ?? '')), $needle));
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
        if (! empty($filters['role_id'])) {
            $rid = (int) $filters['role_id'];
            $rows = array_filter($rows, fn ($r) => ($r['role']['id'] ?? null) === $rid);
        }

        return array_values($rows);
    }
}
