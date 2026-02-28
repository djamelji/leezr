<?php

namespace App\Modules\Core\Theme\Http;

use App\Core\Theme\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class ThemePreferenceController extends Controller
{
    /**
     * Update the authenticated user's theme preference.
     *
     * Company route: PUT /api/company/theme-preference
     * Platform route: PUT /api/platform/theme-preference
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => 'required|string|in:light,dark,system',
        ]);

        // Determine user from guard context
        $user = Auth::guard('platform')->user() ?? $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->theme_preference = $validated['theme'];
        $user->save();

        return response()->json([
            'theme_preference' => ThemeResolver::resolve($user),
        ]);
    }
}
