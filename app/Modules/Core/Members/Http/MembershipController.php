<?php

namespace App\Modules\Core\Members\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldWriteService;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use App\Core\Security\SecurityDetector;
use App\Modules\Core\Members\Http\Requests\StoreMemberRequest;
use App\Modules\Core\Members\Http\Requests\UpdateMemberRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;

class MembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $members = $company->memberships()
            ->with(['user:id,first_name,last_name,email,avatar,password_set_at', 'companyRole:id,key,name'])
            ->get();

        // ADR-168b: bulk completeness (5 queries, no N+1)
        $completeness = CompanyUserProfileReadModel::bulkCompleteness($company, $members);

        $mapped = $members->map(fn (Membership $m) => [
            'id' => $m->id,
            'user' => $m->user->only('id', 'first_name', 'last_name', 'display_name', 'email', 'avatar', 'status'),
            'role' => $m->role,
            'company_role' => $m->companyRole ? $m->companyRole->only('id', 'key', 'name') : null,
            'created_at' => $m->created_at,
            'profile_completeness' => $completeness[$m->id] ?? ['filled' => 0, 'total' => 0, 'complete' => true],
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

        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();
        $isNewUser = false;

        if (!$user) {
            $user = User::create([
                'first_name' => $validated['first_name'] ?? explode('@', $validated['email'])[0],
                'last_name' => $validated['last_name'] ?? '',
                'email' => $validated['email'],
                'password' => null,
            ]);
            $isNewUser = true;
        }

        if ($user->isMemberOf($company)) {
            return response()->json([
                'message' => 'This user is already a member of this company.',
            ], 422);
        }

        $membership = $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $validated['company_role_id'],
        ]);

        $membership->load(['user:id,first_name,last_name,email,avatar,password_set_at', 'companyRole:id,key,name']);

        // Send invitation if user was just created (no password)
        if ($isNewUser) {
            $token = Password::broker('users')->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('members.changed', $company->id, ['action' => 'member.added', 'user_id' => $user->id])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_ADDED, 'user', (string) $user->id);

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
        $baseFields = array_intersect_key($validated, array_flip(['first_name', 'last_name']));
        $profileBefore = $membership->user->only('first_name', 'last_name');
        if (!empty($baseFields)) {
            $membership->user->update($baseFields);
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
        if (array_key_exists('company_role_id', $validated)) {
            if ($membership->isOwner()) {
                return response()->json([
                    'message' => 'Cannot change the role of the owner.',
                ], 403);
            }

            $roleChanged = $membership->company_role_id !== $validated['company_role_id'];
            $membership->update(['company_role_id' => $validated['company_role_id']]);
        }

        // ADR-125: publish after mutation (only on role reassignment)
        if ($roleChanged) {
            app(RealtimePublisher::class)->publish(
                EventEnvelope::invalidation('members.changed', $company->id, ['action' => 'member.role_changed', 'user_id' => $membership->user_id])
            );

            // ADR-130: audit log
            app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_ROLE_CHANGED, 'membership', (string) $membership->id);
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

        $userId = $membership->user_id;
        $membership->delete();

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('members.changed', $company->id, ['action' => 'member.removed'])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::MEMBER_REMOVED, 'user', (string) $userId);

        // ADR-129: detect bulk member removal
        SecurityDetector::check('bulk.member_removal', "user:{$request->user()->id}", $company->id, $request->user()->id);

        return response()->json([
            'message' => 'Member removed.',
        ]);
    }
}
