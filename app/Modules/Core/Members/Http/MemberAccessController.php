<?php

namespace App\Modules\Core\Members\Http;

use App\Core\Models\Membership;
use App\Modules\Core\Members\UseCases\MemberAccessLifecycleUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-768 (M7 LOT 2) — account access lifecycle (suspend / restore / re-send invitation). */
class MemberAccessController
{
    public function __construct(private readonly MemberAccessLifecycleUseCase $lifecycle)
    {
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null;
        $membership = $this->lifecycle->suspend($request->attributes->get('company'), $this->membership($request, $id), $request->user(), $reason);

        return response()->json(['suspended' => $membership->isSuspended()]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $membership = $this->lifecycle->restore($request->attributes->get('company'), $this->membership($request, $id), $request->user());

        return response()->json(['suspended' => $membership->isSuspended()]);
    }

    public function resendInvitation(Request $request, int $id): JsonResponse
    {
        $this->lifecycle->resendInvitation($request->attributes->get('company'), $this->membership($request, $id), $request->user());

        return response()->json(['message' => 'Invitation sent.']);
    }

    private function membership(Request $request, int $id): Membership
    {
        return $request->attributes->get('company')->memberships()->with('user')->findOrFail($id);
    }
}
