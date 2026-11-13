<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Company\Security\MembershipInvitation;
use App\Core\Models\Membership;
use App\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M7 LOT 2 — the invited person's own invitations. Runs OUTSIDE any company context (the user
 * has no access yet): authentication only, strictly limited to HIS pending memberships.
 */
class MyInvitationController
{
    public function index(Request $request): JsonResponse
    {
        $pending = MembershipInvitation::pendingFor($request->user());
        $inviters = User::whereIn('id', $pending->pluck('invited_by')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return response()->json([
            'invitations' => $pending->map(fn (Membership $m) => [
                'id' => $m->id,
                'company' => ['id' => $m->company_id, 'name' => $m->company?->name],
                'role' => $m->companyRole?->name,
                'invited_by' => ($i = $inviters->get($m->invited_by)) ? trim($i->first_name.' '.$i->last_name) : null,
                'invited_at' => $m->acceptance_pending_at?->toIso8601String(),
                'expires_at' => $m->invitation_expires_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        // M7 LOT 3 : une invitation expirée n'est plus acceptable — réponse claire (410), jamais d'activation.
        if (MembershipInvitation::isOwnExpired($request->user(), $id)) {
            return response()->json(['message' => 'This invitation has expired. Ask the company to send it again.', 'code' => 'invitation_expired'], 410);
        }

        $membership = MembershipInvitation::accept($request->user(), $id);
        abort_if($membership === null, 404, 'Invitation not found.');

        return response()->json(['message' => 'Invitation accepted.', 'company_id' => $membership->company_id]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        abort_unless(MembershipInvitation::decline($request->user(), $id), 404, 'Invitation not found.');

        return response()->json(['message' => 'Invitation declined.']);
    }
}
