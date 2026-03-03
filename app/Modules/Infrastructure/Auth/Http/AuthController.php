<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Auth\ReadModels\UserCompaniesReadModel;
use App\Core\Auth\Requests\LoginRequest;
use App\Core\Auth\Requests\RegisterRequest;
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

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterCompanyUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(RegisterCompanyData::fromValidated($request->validated()));

        Auth::login($result->user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => $result->user,
            'company' => $result->company,
            'ui_theme' => UIResolverService::forCompany()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
            'theme_preference' => ThemeResolver::resolve($result->user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (!Auth::attempt($credentials)) {
            // ADR-129: detect suspicious login attempts by IP
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

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user = Auth::user();
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
