<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Markets\Market;
use Illuminate\Http\JsonResponse;

class PublicMarketController
{
    public function index(): JsonResponse
    {
        $markets = Market::active()
            ->with('languages:key,name,native_name')
            ->orderBy('sort_order')
            ->get(['id', 'key', 'name', 'currency', 'locale', 'timezone', 'dial_code', 'is_default', 'sort_order']);

        return response()->json($markets);
    }

    public function show(string $key): JsonResponse
    {
        $market = Market::where('key', $key)
            ->where('is_active', true)
            ->with(['legalStatuses' => fn ($q) => $q->orderBy('sort_order'), 'languages:key,name,native_name'])
            ->firstOrFail();

        return response()->json($market);
    }
}
