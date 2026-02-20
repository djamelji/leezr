<?php

namespace App\Platform\Auth;

use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SessionSettingsPayload;
use App\Core\System\UptimeService;
use App\Core\Theme\UIResolverService;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class PlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::guard('platform')->attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user = Auth::guard('platform')->user();
        $user->load('roles.permissions');

        $permissions = $user->roles
            ->flatMap->permissions
            ->pluck('key')
            ->unique()
            ->values();

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('key'),
            'permissions' => $permissions,
            'platform_modules' => $this->platformModuleNavItems(),
            'ui_theme' => UIResolverService::forPlatform()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'app_meta' => self::appMeta(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('platform');
        $user->load('roles.permissions');

        $permissions = $user->roles
            ->flatMap->permissions
            ->pluck('key')
            ->unique()
            ->values();

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('key'),
            'permissions' => $permissions,
            'platform_modules' => $this->platformModuleNavItems(),
            'ui_theme' => UIResolverService::forPlatform()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'app_meta' => self::appMeta(),
        ]);
    }

    private static function appMeta(): array
    {
        $settings = PlatformSetting::instance();

        return [
            'app_name' => $settings->general['app_name'] ?? 'Leezr',
            'version' => config('app.version'),
            'build_number' => config('app.build_number'),
            'build_date' => config('app.build_date'),
            'commit_hash' => config('app.commit_hash'),
            'uptime' => UptimeService::formatted(),
        ];
    }

    private function platformModuleNavItems(): array
    {
        return collect(ModuleRegistry::forScope('platform'))
            ->filter(fn (ModuleManifest $m) => $m->visibility === 'visible')
            ->flatMap(fn (ModuleManifest $m) => $m->capabilities->navItems)
            ->values()
            ->all();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
