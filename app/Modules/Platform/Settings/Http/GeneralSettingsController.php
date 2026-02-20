<?php

namespace App\Modules\Platform\Settings\Http;

use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class GeneralSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = PlatformSetting::instance();

        return response()->json([
            'general' => [
                'app_name' => $settings->general['app_name'] ?? 'Leezr',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_name' => 'required|string|max:50',
        ]);

        $general = DB::transaction(function () use ($validated) {
            $settings = PlatformSetting::instance();

            $settings->update([
                'general' => array_merge($settings->general ?? [], $validated),
            ]);

            return $settings->fresh()->general;
        });

        return response()->json([
            'general' => $general,
            'message' => 'General settings updated.',
        ]);
    }
}
