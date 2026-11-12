<?php

namespace App\Modules\Core\Members\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Company\RBAC\CompanyRole;
use App\Company\RBAC\MembershipSecurityPolicy;
use App\Company\Security\GlobalIdentityPolicy;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldWriteService;
use App\Core\Models\Membership;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Core\Security\SecurityDetector;
use App\Modules\Core\Members\Http\Requests\StoreMemberRequest;
use App\Modules\Core\Members\Http\Requests\UpdateMemberRequest;
use App\Modules\Core\Members\UseCases\InviteMemberUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MembershipController extends Controller
{
    use PublishesRealtimeEvents;
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $members = $company->memberships()
            ->with(['user:id,first_name,last_name,email,avatar,password_set_at', 'companyRole:id,key,name'])
            ->get();

        // ADR-168b: bulk completeness (5 queries, no N+1)
        $completeness = CompanyUserProfileReadModel::bulkCompleteness($company, $members);

        // P3-A (ADR-686): clarify Members vs Collaborateurs — mark which accounts are
        // also employees (salariés) vs administrative/technical accounts. Light batch
        // read via the user_id bridge, guarded by the workforce module being active
        // (no hard coupling: a company without Workforce simply gets no employee links).
        $employeeByUser = [];
        if (\App\Core\Modules\ModuleGate::isActive($company, 'workforce')) {
            $employeeByUser = \App\Core\Workforce\Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNotNull('user_id')
                ->pluck('id', 'user_id')->all();
        }

        $mapped = $members->map(fn (Membership $m) => [
            'id' => $m->id,
            'user' => $m->user->only('id', 'first_name', 'last_name', 'display_name', 'email', 'avatar', 'status'),
            'role' => $m->role,
            'company_role' => $m->companyRole ? $m->companyRole->only('id', 'key', 'name') : null,
            'created_at' => $m->created_at,
            'profile_completeness' => $completeness[$m->id] ?? ['filled' => 0, 'total' => 0, 'complete' => true],
            // Person link: the account is a collaborator (salarié) if an Employee references its user.
            'employee_id' => $employeeByUser[$m->user_id] ?? null,
            'is_employee' => isset($employeeByUser[$m->user_id]),
        ]);

        return response()->json([
            'members' => $mapped,
            'member_count' => $members->count(),
            'member_limit' => CompanyEntitlements::memberLimit($company),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->with(['user', 'companyRole:id,key,name'])->findOrFail($id);

        $roleKey = $membership->companyRole?->key;
        $canReadSensitive = $request->user()->hasCompanyPermission($company, 'members.sensitive_read');
        $profile = CompanyUserProfileReadModel::get($membership->user, $company, $roleKey, $canReadSensitive);

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'role' => $membership->role,
                'company_role' => $membership->companyRole ? $membership->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $membership->created_at,
            ],
            'base_fields' => $profile['base_fields'],
            'dynamic_fields' => $profile['dynamic_fields'],
            'profile_completeness' => $profile['profile_completeness'],
        ]);
    }

    public function fields(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->with('user')->findOrFail($id);
        $roleKey = $request->query('role_key');
        $canReadSensitive = $request->user()->hasCompanyPermission($company, 'members.sensitive_read');

        $fields = FieldResolverService::resolve(
            model: $membership->user,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $company->id,
            roleKey: $roleKey,
            canReadSensitive: $canReadSensitive,
            marketKey: $company->market_key,
            locale: FieldResolverService::requestLocale(),
        );

        return response()->json(['dynamic_fields' => $fields]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $limit = CompanyEntitlements::memberLimit($company);
        if ($limit !== null && $company->memberships()->count() >= $limit) {
            return response()->json([
                'message' => __('Member limit reached.'),
                'current' => $company->memberships()->count(),
                'limit' => $limit,
            ], 422);
        }

        // ADR-767 : l'attribution du rôle passe par l'autorité serveur UNIQUE (anti-escalade,
        // isolation tenant) AVANT toute écriture — voir InviteMemberUseCase.
        $membership = app(InviteMemberUseCase::class)->execute($company, $request->validated(), $request->user());
        if ($membership === null) {
            return response()->json(['message' => 'This user is already a member of this company.'], 422);
        }
        $user = $membership->user;
        $sensitive = MembershipSecurityPolicy::isSensitiveRole($membership->companyRole);

        // ADR-125: publish after mutation
        $this->publishDomainEvent('members.changed', $company->id, ['action' => 'member_added', 'user_id' => $user->id]);

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_ADDED, 'user', (string) $user->id, [
            'actorId' => $request->user()->id, 'actorType' => 'user', 'severity' => $sensitive ? 'warning' : 'info',
            'metadata' => ['role_id' => $membership->company_role_id, 'is_administrative' => $sensitive],
        ]);

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'user' => $membership->user->only('id', 'first_name', 'last_name', 'display_name', 'email', 'avatar', 'status'),
                'role' => $membership->role,
                'company_role' => $membership->companyRole ? $membership->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $membership->created_at,
            ],
        ], 201);
    }

    public function update(UpdateMemberRequest $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->with('user')->findOrFail($id);
        $validated = $request->validated();

        // ─── Bloc A — Base fields (user table) ─────────────────
        // ADR-767 : seuls les champs RÉELLEMENT modifiés ; refus (422) si l'identité globale est
        // partagée avec une autre entreprise (GlobalIdentityPolicy = autorité unique).
        $baseFields = GlobalIdentityPolicy::authorizedChanges($company, $membership->user, $validated);
        $profileBefore = $membership->user->only('first_name', 'last_name');
        if (!empty($baseFields)) {
            $membership->user->update($baseFields);

            // ADR-752 : propager le nom vers le collaborateur RH lié pour éviter la divergence
            // d'identité. Le modèle Employee est dans Core ; la requête est un no-op si la personne
            // n'a pas de fiche RH (company sans Workforce) → aucune dépendance au module.
            \App\Core\Workforce\Services\LinkedIdentityService::userToEmployee($company->id, $membership->user);
        }

        // ─── Bloc B — Dynamic fields (FieldWriteService) ──────
        if (isset($validated['dynamic_fields'])) {
            FieldWriteService::upsert(
                $membership->user,
                $validated['dynamic_fields'],
                FieldDefinition::SCOPE_COMPANY_USER,
                $company->id,
                $company->market_key,
            );
        }

        // Audit profile changes (base fields or dynamic fields)
        if (!empty($baseFields) || isset($validated['dynamic_fields'])) {
            app(AuditLogger::class)->logCompany(
                $company->id, AuditAction::MEMBER_PROFILE_UPDATED, 'user', (string) $membership->user_id,
                ['diffBefore' => $profileBefore, 'diffAfter' => $membership->user->only('first_name', 'last_name')],
            );
        }

        // ─── Bloc C — Company role (membership pivot) ────────
        $roleChanged = false;
        $assignedSensitive = false;
        if (array_key_exists('company_role_id', $validated)) {
            // Verrou owner (403 historique préservé).
            if ($membership->isOwner()) {
                return response()->json([
                    'message' => 'Cannot change the role of the owner.',
                ], 403);
            }

            // ADR-752 : autorité serveur UNIQUE — anti-escalade / anti auto-promotion / dernier
            // admin / isolation tenant, identique au chemin « accès collaborateur ».
            $newRole = CompanyRole::where('company_id', $company->id)->findOrFail($validated['company_role_id']);
            MembershipSecurityPolicy::assertCanAssignRole($company, $newRole, $request->user(), $membership);

            $roleChanged = $membership->company_role_id !== $newRole->id;
            $assignedSensitive = MembershipSecurityPolicy::isSensitiveRole($newRole);
            $membership->update(['company_role_id' => $newRole->id]);
        }

        // ADR-125: publish after mutation (only on role reassignment)
        if ($roleChanged) {
            $this->publishDomainEvent('members.changed', $company->id, ['action' => 'member_role_changed', 'user_id' => $membership->user_id]);

            // ADR-130 / ADR-752 : audit log — sévérité renforcée pour l'attribution d'un rôle ADMIN.
            app(AuditLogger::class)->logCompany(
                $company->id, AuditAction::MEMBER_ROLE_CHANGED, 'membership', (string) $membership->id,
                ['actorId' => $request->user()->id, 'actorType' => 'user', 'severity' => $assignedSensitive ? 'warning' : 'info',
                 'metadata' => ['role_id' => $membership->company_role_id, 'is_administrative' => $assignedSensitive]],
            );
        }

        $membership = $membership->fresh(['companyRole:id,key,name']);
        $roleKey = $membership->companyRole?->key;
        $canReadSensitive = $request->user()->hasCompanyPermission($company, 'members.sensitive_read');
        $profile = CompanyUserProfileReadModel::get($membership->user, $company, $roleKey, $canReadSensitive);

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'role' => $membership->role,
                'company_role' => $membership->companyRole ? $membership->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $membership->created_at,
            ],
            'base_fields' => $profile['base_fields'],
            'dynamic_fields' => $profile['dynamic_fields'],
            'profile_completeness' => $profile['profile_completeness'],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($id);

        if ($membership->isOwner()) {
            return response()->json([
                'message' => 'Cannot remove the owner from the company.',
            ], 403);
        }

        // ADR-752 : mêmes garde-fous que la révocation d'accès Workforce (plus de contournement
        // par le chemin Members) — pas soi-même, jamais le dernier administrateur actif.
        MembershipSecurityPolicy::assertCanModifyAccess($company, $membership, $request->user());

        $userId = $membership->user_id;

        // Cohérence cycle de vie (ADR-752) : si ce compte est lié à un collaborateur RH, on le
        // DÉLIE (sans supprimer la fiche RH, les contrats, documents, pointages…) pour éviter un
        // `employee.user_id` orphelin. Employee = modèle Core ; no-op si aucune fiche liée.
        \App\Core\Workforce\Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('user_id', $userId)
            ->update(['user_id' => null]);

        $membership->delete();

        // ADR-125: publish after mutation
        $this->publishDomainEvent('members.changed', $company->id, ['action' => 'member_removed', 'user_id' => $userId]);

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_REMOVED, 'user', (string) $userId);

        // ADR-129: detect bulk member removal
        SecurityDetector::check('bulk.member_removal', "user:{$request->user()->id}", $company->id, $request->user()->id);

        return response()->json([
            'message' => 'Member removed.',
        ]);
    }
}
