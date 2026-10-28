<?php

namespace App\Modules\Workforce\Onboarding\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Onboarding\WorkforceOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-637 (W-F) — API de l'assistant d'onboarding RH. État + contexte (lecture), démarrage, complétion
 * d'étape (après succès métier), report, finalisation. Vérifications backend systématiques ; isolation
 * tenant via le contexte Company ; aucune attribution implicite de permission.
 */
class WorkforceOnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(WorkforceOnboardingService::stateWithContext($request->attributes->get('company')));
    }

    public function start(Request $request): JsonResponse
    {
        WorkforceOnboardingService::start($request->attributes->get('company'));

        return response()->json(WorkforceOnboardingService::stateWithContext($request->attributes->get('company')));
    }

    public function completeStep(Request $request): JsonResponse
    {
        $validated = $request->validate(['step' => 'required|string|max:40']);

        return response()->json(WorkforceOnboardingService::completeStep($request->attributes->get('company'), $validated['step']));
    }

    public function deferStep(Request $request): JsonResponse
    {
        $validated = $request->validate(['step' => 'required|string|max:40']);

        return response()->json(WorkforceOnboardingService::deferStep($request->attributes->get('company'), $validated['step']));
    }

    public function finish(Request $request): JsonResponse
    {
        return response()->json(WorkforceOnboardingService::finish($request->attributes->get('company')));
    }
}
