<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;

class PlanController
{
    public function index(): JsonResponse
    {
        return response()->json(
            collect(PlanRegistry::definitions())
                ->map(fn ($def, $key) => ['key' => $key, ...$def])
                ->values()
        );
    }
}
