<?php

namespace App\Modules\Platform\Maintenance\Http;

use App\Core\Settings\MaintenanceSettingsPayload;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class MaintenanceSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'maintenance' => MaintenanceSettingsPayload::fromSettings()->toArray(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'allowlist_ips' => 'present|array',
            'allowlist_ips.*' => 'required|ip',
            'headline' => 'required|string|max:255',
            'subheadline' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'cta_text' => 'required|string|max:100',
            'list_slug' => 'required|string|max:64',
        ]);

        $maintenance = DB::transaction(function () use ($validated) {
            $settings = PlatformSetting::instance();

            $settings->update(['maintenance' => $validated]);

            return $settings->fresh()->maintenance;
        });

        return response()->json([
            'maintenance' => $maintenance,
            'message' => 'Maintenance settings updated.',
        ]);
    }

    public function myIp(Request $request): JsonResponse
    {
        return response()->json(['ip' => $request->ip()]);
    }
}
