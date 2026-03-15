<?php

namespace App\Modules\Infrastructure\AdminAuth\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Auth\TwoFactorService;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use App\Core\Security\SecurityDetector;
use App\Core\Settings\SessionSettingsPayload;
use App\Core\System\UptimeService;
use App\Core\Theme\ThemeResolver;
use App\Core\Theme\UIResolverService;
use App\Platform\Models\PlatformSetting;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Manual credential check (don't log in yet if 2FA is needed)
        $user = PlatformUser::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            SecurityDetector::check('suspicious.login_attempts', $request->ip());

            app(AuditLogger::class)->logPlatform(
                AuditAction::PLATFORM_LOGIN_FAILED,
                'admin',
                null,
                ['actorType' => 'system', 'severity' => 'warning', 'metadata' => ['email' => $credentials['email'], 'ip' => $request->ip()]],
            );

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // ADR-351: Check if 2FA is enabled
        $twoFactor = app(TwoFactorService::class);
        if ($twoFactor->isEnabled($user)) {
            // Store pending 2FA in session, don't fully authenticate yet
            if ($request->hasSession()) {
                $request->session()->regenerate();
                $request->session()->put('2fa_pending_platform_user_id', $user->id);
            }

            return response()->json([
                'requires_2fa' => true,
            ]);
        }

        // No 2FA — log in normally
        Auth::guard('platform')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->buildLoginResponse($user, $request);
    }

    public function verify2fa(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get('2fa_pending_platform_user_id');
        if (!$userId) {
            return response()->json(['message' => 'No pending 2FA verification.'], 422);
        }

        $user = PlatformUser::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 422);
        }

        $twoFactor = app(TwoFactorService::class);
        if (!$twoFactor->verify($user, $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        // 2FA verified — fully authenticate
        $request->session()->forget('2fa_pending_platform_user_id');
        Auth::guard('platform')->login($user);
        $request->session()->regenerate();

        return $this->buildLoginResponse($user, $request);
    }

    private function buildLoginResponse(PlatformUser $user, Request $request): JsonResponse
    {
        $user->load('roles.permissions');

        app(AuditLogger::class)->logPlatform(
            AuditAction::PLATFORM_LOGIN,
            'admin',
            (string) $user->id,
            ['actorId' => $user->id, 'metadata' => ['ip' => $request->ip()]],
        );

        $permissions = $user->roles
            ->flatMap->permissions
            ->pluck('key')
            ->unique()
            ->values();

        $twoFactor = app(TwoFactorService::class);

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('key'),
            'permissions' => $permissions,
            'platform_modules' => $this->platformModuleNavItems(),
            'disabled_modules' => $this->disabledModuleKeys(),
            'ui_theme' => UIResolverService::forPlatform()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'app_meta' => self::appMeta(),
            'theme_preference' => ThemeResolver::resolve($user),
            'two_factor_enabled' => $twoFactor->isEnabled($user),
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

        $twoFactor = app(TwoFactorService::class);

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('key'),
            'permissions' => $permissions,
            'platform_modules' => $this->platformModuleNavItems(),
            'disabled_modules' => $this->disabledModuleKeys(),
            'ui_theme' => UIResolverService::forPlatform()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'app_meta' => self::appMeta(),
            'theme_preference' => ThemeResolver::resolve($user),
            'two_factor_enabled' => $twoFactor->isEnabled($user),
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
        // Legacy flat format for cookie hydration — delegates to NavBuilder
        return NavBuilder::flatForAdmin();
    }

    private function disabledModuleKeys(): array
    {
        return collect(ModuleRegistry::forScope('admin'))
            ->reject(fn (ModuleManifest $m) => ModuleGate::isEnabledGlobally($m->key))
            ->map(fn (ModuleManifest $m) => $m->key)
            ->values()
            ->all();
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::guard('platform')->user();

        if ($user) {
            app(AuditLogger::class)->logPlatform(
                AuditAction::PLATFORM_LOGOUT,
                'admin',
                (string) $user->id,
                ['actorId' => $user->id],
            );
        }

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
