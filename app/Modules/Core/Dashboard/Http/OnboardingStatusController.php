<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Core\Companies\CompanyOnboardingChecklist;
use App\Core\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-383: Onboarding widget — owner-only, dismissible, 6 steps.
 * Replaces ADR-372 permission-filtered approach with owner-exclusive access.
 */
class OnboardingStatusController
{
    /** ADR-834 : single computation, shared with Platform (CompanyOnboardingChecklist). */
    private function stepDefinitions(Company $company): array
    {
        return CompanyOnboardingChecklist::ownerSteps($company);
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();

        if (! $user->isOwnerOf($company)) {
            return response()->json(['message' => 'Owner only.'], 403);
        }

        if ($company->onboarding_dismissed_at !== null) {
            // Return dismissed + steps so frontend can show reopen banner when not all completed
            $steps = $this->stepDefinitions($company);

            $output = array_map(fn (array $s) => [
                'key' => $s['key'],
                'completed' => $s['completed'],
            ], $steps);

            $completedCount = collect($output)->where('completed', true)->count();

            return response()->json([
                'dismissed' => true,
                'steps' => $output,
                'completed_count' => $completedCount,
                'total_count' => count($output),
            ]);
        }

        $steps = $this->stepDefinitions($company);

        $output = array_map(fn (array $s) => [
            'key' => $s['key'],
            'completed' => $s['completed'],
        ], $steps);

        $completedCount = collect($output)->where('completed', true)->count();

        return response()->json([
            'steps' => $output,
            'completed_count' => $completedCount,
            'total_count' => count($output),
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();

        if (! $user->isOwnerOf($company)) {
            return response()->json(['message' => 'Owner only.'], 403);
        }

        $company->update(['onboarding_dismissed_at' => now()]);

        return response()->json(['dismissed' => true]);
    }

    public function reopen(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();

        if (! $user->isOwnerOf($company)) {
            return response()->json(['message' => 'Owner only.'], 403);
        }

        $company->update(['onboarding_dismissed_at' => null]);

        // Return fresh steps (same as __invoke)
        return $this->__invoke($request);
    }
}
