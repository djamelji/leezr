<?php

namespace App\Core\Auth;

use App\Core\Auth\Requests\LoginRequest;
use App\Core\Auth\Requests\RegisterRequest;
use App\Core\Models\Company;
use App\Core\Models\User;
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

            $company = Company::create([
                'name' => $validated['company_name'],
                'slug' => Str::slug($validated['company_name']) . '-' . Str::random(4),
            ]);

            $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            return ['user' => $user, 'company' => $company];
        });

        Auth::login($result['user']);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

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
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => Auth::user(),
            'ui_theme' => UIResolverService::forCompany()->toArray(),
            'ui_session' => SessionSettingsPayload::fromSettings()->toFrontendArray(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
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
            // Owner/admin are always administrative, regardless of CompanyRole
            $isAdministrative = in_array($membership->role, ['owner', 'admin'])
                || (bool) $membership->companyRole?->is_administrative;

            $data = [
                'id' => $membership->company->id,
                'name' => $membership->company->name,
                'slug' => $membership->company->slug,
                'role' => $membership->role,
                'is_administrative' => $isAdministrative,
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
