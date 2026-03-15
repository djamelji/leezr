<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Auth\ReadModels\UserCompaniesReadModel;
use App\Core\Auth\TwoFactorService;
use App\Core\Models\User;
use App\Modules\Infrastructure\Auth\Http\Requests\LoginRequest;
use App\Modules\Infrastructure\Auth\Http\Requests\RegisterRequest;
use App\Core\Security\SecurityDetector;
use App\Core\Settings\SessionSettingsPayload;
use App\Core\Theme\ThemeResolver;
use App\Core\Theme\UIResolverService;
use App\Modules\Infrastructure\Auth\UseCases\RegisterCompanyData;
use App\Modules\Infrastructure\Auth\UseCases\RegisterCompanyUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterCompanyUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(RegisterCompanyData::fromValidated($request->validated()));

        Auth::login($result->user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $response = [
            'user' => $result->user,
            'company' => $result->company,
            'ui_theme' => UIResolverService::forCompany()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'theme_preference' => ThemeResolver::resolve($result->user),
        ];

        if ($result->checkout) {
            $response['checkout'] = $result->checkout->toArray();
        }

        return response()->json($response, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        // Manual credential check (don't log in yet if 2FA is needed)
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            SecurityDetector::check('suspicious.login_attempts', $request->ip());

            app(AuditLogger::class)->logPlatform(
                AuditAction::LOGIN_FAILED,
                'user',
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
            if ($request->hasSession()) {
                $request->session()->regenerate();
                $request->session()->put('2fa_pending_user_id', $user->id);
            }

            return response()->json([
                'requires_2fa' => true,
            ]);
        }

        // No 2FA — log in normally
        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->buildLoginResponse($user, $request);
    }

    public function verify2fa(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get('2fa_pending_user_id');
        if (!$userId) {
            return response()->json(['message' => 'No pending 2FA verification.'], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 422);
        }

        $twoFactor = app(TwoFactorService::class);
        if (!$twoFactor->verify($user, $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        $request->session()->forget('2fa_pending_user_id');
        Auth::login($user);
        $request->session()->regenerate();

        return $this->buildLoginResponse($user, $request);
    }

    private function buildLoginResponse(User $user, Request $request): JsonResponse
    {
        $membership = $user->memberships()->first();

        if ($membership) {
            app(AuditLogger::class)->logCompany(
                $membership->company_id,
                AuditAction::LOGIN,
                'user',
                (string) $user->id,
                ['actorId' => $user->id, 'metadata' => ['ip' => $request->ip()]],
            );
        }

        return response()->json([
            'user' => $user,
            'ui_theme' => UIResolverService::forCompany()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'theme_preference' => ThemeResolver::resolve($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user) {
            $membership = $user->memberships()->first();

            if ($membership) {
                app(AuditLogger::class)->logCompany(
                    $membership->company_id,
                    AuditAction::LOGOUT,
                    'user',
                    (string) $user->id,
                    ['actorId' => $user->id],
                );
            }
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'ui_theme' => UIResolverService::forCompany()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'theme_preference' => ThemeResolver::resolve($request->user()),
        ]);
    }

    public function myCompanies(Request $request): JsonResponse
    {
        return response()->json([
            'companies' => UserCompaniesReadModel::forUser($request->user()),
        ]);
    }
}
