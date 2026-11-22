<?php

namespace App\Modules\Core\People\Http;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Workforce\Employee;
use App\Modules\Core\People\PersonRef;
use App\Modules\Core\People\ReadModels\PeopleListReadModel;
use App\Modules\Core\People\ReadModels\PersonFileReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-753 (Phase 1) — fiche Collaborateur unifiée (Core). `GET /company/people/{personRef}`
 * agrège User/Membership/Employee/CompanyRole + sections Workforce autorisées. Gated `members.view`
 * (Core) : accessible SANS le module Workforce. Les mutations (rôle, accès, RH) passent par les
 * endpoints sécurisés existants (MembershipSecurityPolicy, EmployeeAccessService) — aucun nouveau
 * chemin moins restrictif.
 */
class PersonController extends Controller
{
    /** Liste Collaborateurs unifiée (facettes accès/RH selon permissions). */
    public function index(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $filters = $request->only(['search', 'employment_status', 'access_state', 'department', 'job_role', 'contract_type', 'manager', 'attention', 'role_id', 'sort_by', 'sort_dir']); // ADR-858 : poste + « à traiter » ; ADR-864 : contrat, responsable

        return response()->json(PeopleListReadModel::list(
            $company,
            $request->user(),
            $filters,
            (int) $request->query('per_page', 20),
            (int) $request->query('page', 1),
        ));
    }

    public function show(Request $request, string $personRef): JsonResponse
    {
        $ref = PersonRef::parse($personRef);
        if (! $ref) {
            return response()->json(['message' => 'Invalid person reference.'], 422);
        }

        /** @var Company $company */
        $company = $request->attributes->get('company');
        $data = PersonFileReadModel::get($company, $ref, $request->user());

        if ($data === null) {
            return response()->json(['message' => 'Person not found in this company.'], 404);
        }

        return response()->json($data);
    }

    /**
     * ADR-768 (M7 LOT 2) — résout un identifiant HISTORIQUE vers la référence canonique de la personne.
     * `membership_id` (ancienne route /company/members/:id) exige la facette ACCÈS ; `employee_id`
     * (ancienne route /company/workforce/employees/:id) exige la facette RH. Un id n'est jamais
     * interprété dans l'autre espace : un id Membership n'est pas un id Employee.
     */
    public function resolve(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $actor = $request->user();
        $data = $request->validate([
            'membership_id' => 'required_without:employee_id|integer',
            'employee_id' => 'required_without:membership_id|integer',
        ]);

        if (isset($data['membership_id'])) {
            abort_unless($actor->hasCompanyPermission($company, 'members.view'), 403);
            $userId = Membership::where('company_id', $company->id)->whereKey($data['membership_id'])->value('user_id');
            abort_if($userId === null, 404, 'Person not found in this company.');
            $employeeId = \App\Core\Modules\ModuleGate::isActive($company, 'workforce')
                ? Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $userId)->value('id')
                : null;

            return response()->json(['person_ref' => (string) ($employeeId ? PersonRef::forEmployee($employeeId) : PersonRef::forUser($userId))]);
        }

        abort_unless($actor->hasCompanyPermission($company, 'workforce.view'), 403);
        $exists = Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($data['employee_id'])->exists();
        abort_unless($exists, 404, 'Person not found in this company.');

        return response()->json(['person_ref' => (string) PersonRef::forEmployee((int) $data['employee_id'])]);
    }

    /**
     * Historique d'audit RÉEL de la personne (jamais inventé). Nécessite `audit.view`.
     */
    public function history(Request $request, string $personRef): JsonResponse
    {
        if (! $request->user()->hasCompanyPermission($request->attributes->get('company'), 'audit.view')) {
            abort(403, 'Not authorized to view history.');
        }

        $ref = PersonRef::parse($personRef);
        if (! $ref) {
            return response()->json(['message' => 'Invalid person reference.'], 422);
        }

        /** @var Company $company */
        $company = $request->attributes->get('company');

        // Résout la personne (lien explicite, tenant-scopé) pour cibler ses traces.
        [$userId, $employeeId] = $this->resolveTargets($company, $ref);
        if ($userId === null && $employeeId === null) {
            return response()->json(['entries' => []]);
        }

        $targets = [];
        if ($userId !== null) {
            $targets[] = ['user', (string) $userId];
            $membershipId = Membership::where('company_id', $company->id)->where('user_id', $userId)->value('id');
            if ($membershipId) {
                $targets[] = ['membership', (string) $membershipId];
            }
        }
        if ($employeeId !== null) {
            $targets[] = ['employee', (string) $employeeId];
            $targets[] = ['employee_access', (string) $employeeId];
        }

        $entries = CompanyAuditLog::where('company_id', $company->id)
            ->where(function ($w) use ($targets) {
                foreach ($targets as [$type, $id]) {
                    $w->orWhere(fn ($x) => $x->where('target_type', $type)->where('target_id', $id));
                }
            })
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'action', 'actor_type', 'severity', 'target_type', 'created_at'])
            ->map(fn ($e) => [
                'id' => $e->id, 'action' => $e->action, 'actor_type' => $e->actor_type,
                'severity' => $e->severity, 'target_type' => $e->target_type,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json(['entries' => $entries]);
    }

    /** @return array{0: ?int, 1: ?int}  [userId, employeeId] résolus dans la company. */
    private function resolveTargets(Company $company, PersonRef $ref): array
    {
        if ($ref->isUser()) {
            $isMember = Membership::where('company_id', $company->id)->where('user_id', $ref->id)->exists();
            if (! $isMember) {
                return [null, null];
            }
            $empId = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $ref->id)->value('id');

            return [$ref->id, $empId];
        }

        $emp = Employee::withoutGlobalScopes()->where('company_id', $company->id)->find($ref->id);

        return $emp ? [$emp->user_id, $emp->id] : [null, null];
    }
}
