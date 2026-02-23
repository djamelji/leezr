<?php

namespace App\Modules\Platform\Settings\Http;

use App\Core\Settings\WorldSettingsPayload;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class WorldSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'world' => WorldSettingsPayload::fromSettings()->toArray(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => 'required|string|size:2',
            'currency' => 'required|string|size:3',
            'locale' => 'required|string|max:10',
            'timezone' => 'required|string|timezone:all',
            'dial_code' => 'required|string|max:6',
        ]);

        $world = DB::transaction(function () use ($validated) {
            $settings = PlatformSetting::instance();

            $settings->update(['world' => $validated]);

            return $settings->fresh()->world;
        });

        return response()->json([
            'world' => $world,
            'message' => 'World settings updated.',
        ]);
    }
}
