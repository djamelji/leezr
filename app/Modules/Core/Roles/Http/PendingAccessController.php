<?php

namespace App\Modules\Core\Roles\Http;

use App\Core\Modules\Setup\PendingModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-809 — grouped confirmation of the access prepared for a new company (or held at a module's first
 * activation) : one review, one decision. Same engine : PendingModuleAccess::confirm (delegation policy, audit).
 */
class PendingAccessController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(PendingModuleAccess::summary($request->attributes->get('company'), $request->user()));
    }

    public function confirm(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $fingerprint = $request->validate(['fingerprint' => 'required|string|size:40'])['fingerprint'];
        if (! hash_equals(PendingModuleAccess::fingerprint($company), $fingerprint)) {
            return response()->json(['message' => 'The access to confirm changed meanwhile.', 'code' => 'stale_review'], 409);
        }
        $out = PendingModuleAccess::confirm($company, $request->user(), null, null, 'grouped_review');

        return response()->json($out + PendingModuleAccess::summary($company, $request->user()));
    }
}
