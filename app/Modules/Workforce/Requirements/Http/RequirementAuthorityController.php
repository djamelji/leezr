<?php

namespace App\Modules\Workforce\Requirements\Http;

use App\Modules\Workforce\Requirements\RequirementAuthoritySwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-818 — per-company requirement authority : preview (read-only) and explicit switch (settings.manage). */
class RequirementAuthorityController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json((new RequirementAuthoritySwitch($request->attributes->get('company')))->preview());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['authority' => 'required|in:role,position', 'accept_losses' => 'sometimes|boolean']);

        return response()->json((new RequirementAuthoritySwitch($request->attributes->get('company')))
            ->switch($data['authority'], $request->user(), (bool) ($data['accept_losses'] ?? false)));
    }
}
