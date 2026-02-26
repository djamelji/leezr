<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Auth\Requests\LoginRequest;
use App\Core\Auth\Requests\RegisterRequest;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Security\SecurityDetector;
use App\Core\Settings\SessionSettingsPayload;
use App\Core\Theme\UIResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = DB::transaction(function () use ($validated) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_set_at' => now(),
            ]);

            $planKey = $validated['plan_key'] ?? 'starter';

            $company = Company::create([
                'name' => $validated['company_name'],
                'slug' => Str::slug($validated['company_name']) . '-' . Str::random(4),
                'plan_key' => $planKey,
            ]);

            $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            // ADR-100: Assign jobdomain + activate defaults if provided
            $jobdomainKey = $validated['jobdomain_key'] ?? null;

            if ($jobdomainKey) {
                JobdomainGate::assignToCompany($company, $jobdomainKey);
            }

            return ['user' => $user, 'company' => $company];
        });

        Auth::login($result['user']);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        app(AuditLogger::class)->logCompany(
            $result['company']->id,
            AuditAction::REGISTER,
            'user',
            (string) $result['user']->id,
            ['actorId' => $result['user']->id, 'metadata' => ['email' => $result['user']->email]],
        );

        return response()->json([
            'user' => $result['user'],
            'company' => $result['company'],
            'ui_theme' => UIResolverService::forCompany()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
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
        ]);
    }

    public function myCompanies(Request $request): JsonResponse
    {
        $user = $request->user();

        // Eager-load memberships with their RBAC role + permissions
        $memberships = $user->memberships()
            ->with('companyRole.permissions', 'company')
            ->get();

        $companies = $memberships->map(function ($membership) {
            // Delegate to Membership::isAdmin() — single source of truth
            $isAdministrative = $membership->isAdmin();

            $data = [
                'id' => $membership->company->id,
                'name' => $membership->company->name,
                'slug' => $membership->company->slug,
                'role' => $membership->role,
                'is_administrative' => $isAdministrative,
                'plan_key' => CompanyEntitlements::planKey($membership->company),
            ];

            if ($membership->companyRole) {
                $data['company_role'] = [
                    'id' => $membership->companyRole->id,
                    'key' => $membership->companyRole->key,
                    'name' => $membership->companyRole->name,
                    'is_administrative' => (bool) $membership->companyRole->is_administrative,
                    'permissions' => $membership->companyRole->permissions->pluck('key')->values(),
                ];
            } else {
                $data['company_role'] = null;
            }

            return $data;
        });

        return response()->json([
            'companies' => $companies,
        ]);
    }
}
