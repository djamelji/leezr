<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-372: Onboarding steps filtered by user permissions.
 * Each step declares its required permission (null = always visible).
 * Owner bypasses (sees all steps). Non-owner sees only steps they can act on.
 */
class OnboardingStatusController
{
    /**
     * Step definitions with permission requirements.
     * Permission = null means always visible.
     */
    private function stepDefinitions(Company $company): array
    {
        return [
            [
                'key' => 'account_created',
                'permission' => null,
                'completed' => true,
            ],
            [
                'key' => 'plan_selected',
                'permission' => 'billing.manage',
                'completed' => Subscription::where('company_id', $company->id)
                    ->where('is_current', true)
                    ->exists(),
            ],
            [
                'key' => 'company_profile',
                'permission' => 'settings.manage',
                'completed' => ! empty($company->address_line1) || ! empty($company->tax_id),
            ],
            [
                'key' => 'payment_method',
                'permission' => 'billing.manage',
                'completed' => CompanyPaymentProfile::where('company_id', $company->id)->exists(),
            ],
            [
                'key' => 'invite_member',
                'permission' => 'members.invite',
                'completed' => $company->memberships()->count() > 1,
            ],
        ];
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();
        $isOwner = $user->isOwnerOf($company);

        $allSteps = $this->stepDefinitions($company);

        // Filter steps by user permissions (owner sees all)
        $steps = array_values(array_filter($allSteps, function (array $step) use ($company, $user, $isOwner) {
            if ($isOwner || $step['permission'] === null) {
                return true;
            }

            return $user->hasCompanyPermission($company, $step['permission']);
        }));

        // Strip permission key from response (frontend doesn't need it)
        $steps = array_map(fn (array $s) => [
            'key' => $s['key'],
            'completed' => $s['completed'],
        ], $steps);

        $completedCount = collect($steps)->where('completed', true)->count();

        return response()->json([
            'steps' => $steps,
            'completed_count' => $completedCount,
            'total_count' => count($steps),
        ]);
    }
}
