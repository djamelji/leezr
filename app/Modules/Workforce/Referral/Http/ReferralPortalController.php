<?php

namespace App\Modules\Workforce\Referral\Http;

use App\Modules\Workforce\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-819 — PUBLIC referral page for the candidate. The token travels in the request BODY only (the link
 * carries it in the URL fragment : never sent to a server, never in a Referer). Invalid, expired or used
 * links all answer the same 404 — nothing to enumerate.
 */
class ReferralPortalController
{
    public function view(Request $request): JsonResponse
    {
        $ref = ReferralService::byToken((string) $request->validate(['token' => 'required|string|max:80'])['token']);
        abort_if($ref === null, 404, __('referral.link_invalid'));

        return response()->json(ReferralService::portalView($ref));
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:80', 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:191', 'phone' => 'nullable|string|max:40', 'message' => 'nullable|string|max:2000', 'consent' => 'accepted',
            'website' => 'prohibited', // pot de miel : un robot qui remplit tout est refusé
        ]);
        $ref = ReferralService::byToken($data['token']);
        abort_if($ref === null, 404, __('referral.link_invalid'));
        ReferralService::apply($ref, $data);

        return response()->json(['ok' => true]);
    }
}
