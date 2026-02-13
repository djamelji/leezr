<?php

namespace App\Company\Http\Controllers;

use App\Company\Http\Requests\StoreMemberRequest;
use App\Company\Http\Requests\UpdateMemberRequest;
use App\Core\Models\Membership;
use App\Core\Models\User;
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
            ->with('user:id,name,email,avatar,password_set_at')
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'user' => $m->user->only('id', 'name', 'email', 'avatar', 'status'),
                'role' => $m->role,
                'created_at' => $m->created_at,
            ]);

        return response()->json([
            'members' => $members,
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
                'name' => explode('@', $validated['email'])[0],
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
            'role' => $validated['role'],
        ]);

        $membership->load('user:id,name,email,avatar,password_set_at');

        // Send invitation if user was just created (no password)
        if ($isNewUser) {
            $token = Password::broker('users')->createToken($user);
            $user->sendPasswordResetNotification($token);
        }

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'user' => $membership->user->only('id', 'name', 'email', 'avatar', 'status'),
                'role' => $membership->role,
                'created_at' => $membership->created_at,
            ],
        ], 201);
    }

    public function update(UpdateMemberRequest $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($id);

        if ($membership->isOwner()) {
            return response()->json([
                'message' => 'Cannot change the role of the owner.',
            ], 403);
        }

        $membership->update($request->validated());

        $membership->load('user:id,name,email,avatar,password_set_at');

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'user' => $membership->user->only('id', 'name', 'email', 'avatar', 'status'),
                'role' => $membership->role,
                'created_at' => $membership->created_at,
            ],
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
