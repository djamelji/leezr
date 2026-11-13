<?php

namespace App\Modules\Core\Members\Http;

use App\Modules\Core\Members\ReadModels\PersonalSpaceReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-769 (M7 LOT 3) — « Mon espace » : personal capabilities of the connected person (self scope). */
class PersonalSpaceController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(PersonalSpaceReadModel::get($request->attributes->get('company'), $request->user()));
    }
}
