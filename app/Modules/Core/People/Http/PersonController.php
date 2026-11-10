<?php

namespace App\Modules\Core\People\Http;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Workforce\Employee;
use App\Modules\Core\People\PersonRef;
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
