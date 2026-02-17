<?php

namespace App\Modules\Core\Members\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldWriteService;
use App\Core\Models\Membership;
use App\Core\Models\User;
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
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'user' => $m->user->only('id', 'first_name', 'last_name', 'display_name', 'email', 'avatar', 'status'),
                'role' => $m->role,
                'company_role' => $m->companyRole ? $m->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $m->created_at,
            ]);

        return response()->json([
            'members' => $members,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->with(['user', 'companyRole:id,key,name'])->findOrFail($id);

        $profile = CompanyUserProfileReadModel::get($membership->user, $company);

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'role' => $membership->role,
                'company_role' => $membership->companyRole ? $membership->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $membership->created_at,
            ],
            'base_fields' => $profile['base_fields'],
            'dynamic_fields' => $profile['dynamic_fields'],
        ]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
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
            'company_role_id' => $validated['company_role_id'] ?? null,
        ]);

        $membership->load(['user:id,first_name,last_name,email,avatar,password_set_at', 'companyRole:id,key,name']);

        // Send invitation if user was just created (no password)
        if ($isNewUser) {
            $token = Password::broker('users')->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

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
            );
        }

        // ─── Bloc C — Company role (membership pivot) ────────
        if (array_key_exists('company_role_id', $validated)) {
            if ($membership->isOwner()) {
                return response()->json([
                    'message' => 'Cannot change the role of the owner.',
                ], 403);
            }

            $membership->update(['company_role_id' => $validated['company_role_id']]);
        }

        $membership = $membership->fresh(['companyRole:id,key,name']);
        $profile = CompanyUserProfileReadModel::get($membership->user, $company);

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'role' => $membership->role,
                'company_role' => $membership->companyRole ? $membership->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $membership->created_at,
            ],
            'base_fields' => $profile['base_fields'],
            'dynamic_fields' => $profile['dynamic_fields'],
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

        $membership->delete();

        return response()->json([
            'message' => 'Member removed.',
        ]);
    }
}
