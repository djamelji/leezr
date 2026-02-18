<?php

namespace App\Modules\Platform\Settings\Http;

use App\Core\Settings\SessionSettingsPayload;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class SessionSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'session' => SessionSettingsPayload::fromSettings()->toArray(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idle_timeout' => 'required|integer|min:5|max:1440',
            'warning_threshold' => 'required|integer|min:1|max:30',
            'heartbeat_interval' => 'required|integer|min:1|max:60',
            'remember_me_enabled' => 'required|boolean',
            'remember_me_duration' => 'required|integer|min:1440|max:131400',
        ]);

        // Guards: warning and heartbeat must be less than idle timeout
        if ($validated['warning_threshold'] >= $validated['idle_timeout']) {
            return response()->json([
                'message' => 'Warning threshold must be less than idle timeout.',
            ], 422);
        }

        if ($validated['heartbeat_interval'] >= $validated['idle_timeout']) {
            return response()->json([
                'message' => 'Heartbeat interval must be less than idle timeout.',
            ], 422);
        }

        $session = DB::transaction(function () use ($validated) {
            $settings = PlatformSetting::instance();

            $settings->update(['session' => $validated]);

            return $settings->fresh()->session;
        });

        return response()->json([
            'session' => $session,
            'message' => 'Session settings updated.',
        ]);
    }
}
