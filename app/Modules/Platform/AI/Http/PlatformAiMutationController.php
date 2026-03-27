<?php

namespace App\Modules\Platform\AI\Http;

use App\Core\Ai\AiProviderRegistry;
use App\Modules\Platform\AI\AiGovernanceCrudService;
use App\Modules\Platform\AI\Http\Requests\UpdateAiConfigRequest;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Write endpoints for Platform AI admin.
 * Permission: manage_ai
 */
class PlatformAiMutationController
{
    public function updateConfig(UpdateAiConfigRequest $request): JsonResponse
    {
        $settings = PlatformSetting::instance();

        $current = $settings->ai ?? [];
        $settings->update([
            'ai' => array_merge($current, $request->validated()),
        ]);

        return response()->json(['message' => 'AI configuration updated.', 'config' => $settings->fresh()->ai]);
    }

    public function installProvider(string $providerKey): JsonResponse
    {
        $manifest = AiProviderRegistry::get($providerKey);
        if (! $manifest) {
            return response()->json(['message' => 'Unknown provider key.'], 422);
        }

        $module = AiGovernanceCrudService::installModule(
            $providerKey,
            $manifest->name,
            $manifest->description,
        );

        return response()->json(['message' => "Provider {$module->name} installed.", 'provider' => $module]);
    }

    public function activateProvider(string $providerKey): JsonResponse
    {
        $module = AiGovernanceCrudService::activateModule($providerKey);

        return response()->json(['message' => "Provider {$module->name} activated.", 'provider' => $module]);
    }

    public function deactivateProvider(string $providerKey): JsonResponse
    {
        $module = AiGovernanceCrudService::deactivateModule($providerKey);

        return response()->json(['message' => "Provider {$module->name} deactivated.", 'provider' => $module]);
    }

    public function updateProviderCredentials(Request $request, string $providerKey): JsonResponse
    {
        $data = $request->validate([
            'credentials' => 'required|array',
        ]);

        $module = AiGovernanceCrudService::updateModuleCredentials($providerKey, $data['credentials']);

        return response()->json(['message' => "Provider {$module->name} credentials updated."]);
    }

    public function updateRouting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'routing' => 'required|array',
            'routing.vision' => 'nullable|string',
            'routing.completion' => 'nullable|string',
            'routing.text_extraction' => 'nullable|string',
        ]);

        $settings = PlatformSetting::instance();
        $current = $settings->ai ?? [];
        $current['routing'] = $data['routing'];

        $settings->update(['ai' => $current]);

        return response()->json(['message' => 'Capability routing updated.', 'routing' => $data['routing']]);
    }

    public function healthCheck(string $providerKey): JsonResponse
    {
        try {
            $result = AiGovernanceCrudService::checkModuleHealth($providerKey);

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
