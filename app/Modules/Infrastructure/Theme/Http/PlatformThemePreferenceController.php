<?php

namespace App\Modules\Infrastructure\Theme\Http;

use App\Core\Theme\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Platform user theme preference — infrastructure-level (no module gate).
 * Platform admins always have theme control.
 */
class PlatformThemePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => 'required|string|in:light,dark,system',
        ]);

        $user = $request->user('platform');

        $user->theme_preference = $validated['theme'];
        $user->save();

        return response()->json([
            'theme_preference' => ThemeResolver::resolve($user),
        ]);
    }
}
