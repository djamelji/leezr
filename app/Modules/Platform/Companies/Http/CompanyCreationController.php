<?php

namespace App\Modules\Platform\Companies\Http;

use App\Company\Security\MembershipInvitation;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Subscription;
use App\Core\Models\Membership;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Plans\PlanRegistry;
use App\Modules\Platform\Companies\Creation\CreatePlatformCompanyUseCase;
use App\Modules\Platform\Companies\Creation\PlatformCompanyCreationCatalog;
use App\Modules\Platform\Companies\Http\Requests\CreatePlatformCompanyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** ADR-834 — Platform « New company » wizard + onboarding follow-up. */
class CompanyCreationController extends Controller
{
    /** GET /platform/company-creation/options */
    public function options(Request $request): JsonResponse
    {
        return response()->json(PlatformCompanyCreationCatalog::options() + [
            'can_override' => $request->user('platform')->hasPermission(CreatePlatformCompanyUseCase::OVERRIDE_PERMISSION),
        ]);
    }

    /** GET /platform/company-creation/preview?jobdomain=&plan=&market= */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'jobdomain' => ['required', 'string', Rule::exists('jobdomains', 'key')->where('is_active', true)],
            'plan' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'market' => ['sometimes', 'nullable', 'string', 'exists:markets,key'],
        ]);
        $jd = $request->query('jobdomain');
        $plan = $request->query('plan');

        return response()->json([
            'modules' => PlatformCompanyCreationCatalog::modules($jd, $plan),
            'initialization' => PlatformCompanyCreationCatalog::initialization($jd, $request->query('market'), $plan),
        ]);
    }

    /** GET /platform/company-creation/administrator?email= — existing account? trial already used? */
    public function administrator(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($request->query('email')))])->first();
        if (! $user) {
            return response()->json(['exists' => false, 'trial_used' => false, 'active' => false]);
        }

        return response()->json([
            'exists' => true,
            'active' => $user->password_set_at !== null,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'trial_used' => Subscription::whereIn('company_id', $user->companies()->pluck('companies.id'))->whereNotNull('trial_ends_at')->exists(),
        ]);
    }

    /** POST /platform/companies */
    public function store(CreatePlatformCompanyRequest $request, CreatePlatformCompanyUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->validated(), $request->user('platform'));
        $company = $result['company'];

        return response()->json([
            'company' => ['id' => $company->id, 'name' => $company->name, 'slug' => $company->slug],
            'replayed' => $result['replayed'],
            'onboarding' => CreatePlatformCompanyUseCase::onboarding($company),
        ], $result['replayed'] ? 200 : 201);
    }

    /** GET /platform/companies/{id}/onboarding */
    public function onboarding(int $id): JsonResponse
    {
        return response()->json(CreatePlatformCompanyUseCase::onboarding(Company::findOrFail($id)));
    }

    /** POST /platform/companies/{id}/onboarding/resend-invitation — resume : the administrator has not activated yet */
    public function resendInvitation(int $id, AuditLogger $audit): JsonResponse
    {
        $company = Company::findOrFail($id);
        $owner = Membership::where('company_id', $company->id)->where('role', 'owner')->oldest('id')->firstOrFail();
        $summary = MembershipInvitation::resend($company, $owner, null);
        $audit->logPlatform('company.administrator_invitation_resent', 'company', (string) $company->id, ['metadata' => ['membership_id' => $owner->id]]);

        return response()->json(['invitation' => $summary, 'onboarding' => CreatePlatformCompanyUseCase::onboarding($company)]);
    }
}
