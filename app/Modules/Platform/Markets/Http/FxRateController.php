<?php

namespace App\Modules\Platform\Markets\Http;

use App\Core\Markets\FxRate;
use App\Core\Markets\Jobs\FxRateFetchJob;
use Illuminate\Http\JsonResponse;

class FxRateController
{
    public function index(): JsonResponse
    {
        $rates = FxRate::orderBy('base_currency')
            ->orderBy('target_currency')
            ->get();

        return response()->json([
            'rates' => $rates,
        ]);
    }

    public function refresh(): JsonResponse
    {
        FxRateFetchJob::dispatchSync();

        return response()->json([
            'message' => 'FX rates refreshed.',
        ]);
    }
}
