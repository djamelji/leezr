<?php

namespace App\Modules\Platform\Settings\Http;

use App\Core\FeatureFlag\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class FeatureFlagController extends Controller
{
    public function index(FeatureFlagService $service): JsonResponse
    {
        return response()->json($service->list());
    }

    public function store(Request $request, FeatureFlagService $service): JsonResponse
    {
        $data = $request->validate([
            'key' => 'required|string|max:100|regex:/^[a-z0-9._-]+$/',
            'description' => 'nullable|string|max:255',
            'enabled_globally' => 'boolean',
        ]);

        $flag = $service->createOrUpdate($data);

        return response()->json($flag, 201);
    }

    public function update(Request $request, string $key, FeatureFlagService $service): JsonResponse
    {
        $data = $request->validate([
            'description' => 'nullable|string|max:255',
            'enabled_globally' => 'boolean',
        ]);

        $data['key'] = $key;
        $flag = $service->createOrUpdate($data);

        return response()->json($flag);
    }

    public function destroy(string $key, FeatureFlagService $service): JsonResponse
    {
        $service->delete($key);

        return response()->json(['ok' => true]);
    }

    public function toggle(Request $request, string $key, FeatureFlagService $service): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $flag = $service->toggleGlobal($key, $data['enabled']);

        return $flag ? response()->json($flag) : response()->json(['error' => 'Not found'], 404);
    }

    public function companyOverride(Request $request, string $key, FeatureFlagService $service): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'enabled' => 'nullable|boolean',
        ]);

        $flag = $service->setCompanyOverride($key, $data['company_id'], $data['enabled']);

        return $flag ? response()->json($flag) : response()->json(['error' => 'Not found'], 404);
    }
}
