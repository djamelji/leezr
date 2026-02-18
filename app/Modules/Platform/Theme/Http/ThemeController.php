<?php

namespace App\Modules\Platform\Theme\Http;

use App\Core\Theme\ThemePayload;
use App\Core\Theme\UIResolverService;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThemeController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'theme' => UIResolverService::forPlatform()->toArray(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => 'required|in:light,dark,system',
            'skin' => 'required|in:default,bordered',
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_darken_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'layout' => 'required|in:vertical,horizontal',
            'nav_collapsed' => 'required|boolean',
            'semi_dark' => 'required|boolean',
            'navbar_blur' => 'required|boolean',
            'content_width' => 'required|in:boxed,fluid',
        ]);

        // Explicit boolean cast (never trust request casting)
        $validated['nav_collapsed'] = (bool) $validated['nav_collapsed'];
        $validated['semi_dark'] = (bool) $validated['semi_dark'];
        $validated['navbar_blur'] = (bool) $validated['navbar_blur'];

        // Defensive guard: horizontal layout → force nav_collapsed=false, semi_dark=false
        if ($validated['layout'] === 'horizontal') {
            $validated['nav_collapsed'] = false;
            $validated['semi_dark'] = false;
        }

        // Transaction: read singleton + update
        $theme = DB::transaction(function () use ($validated) {
            $setting = PlatformSetting::instance();
            $setting->update(['theme' => $validated]);

            return $setting->fresh()->theme;
        });

        return response()->json([
            'theme' => $theme,
            'message' => 'Theme settings updated.',
        ]);
    }
}
