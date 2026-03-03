<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\ReadModels\PlatformPaymentGovernanceReadService;
use App\Modules\Platform\Billing\PaymentGovernanceCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentModuleController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'modules' => PlatformPaymentGovernanceReadService::listModules(),
        ]);
    }

    public function install(string $providerKey): JsonResponse
    {
        $manifest = PaymentRegistry::get($providerKey);

        if (! $manifest) {
            return response()->json(['message' => 'Unknown payment provider.'], 404);
        }

        $module = PaymentGovernanceCrudService::installModule($providerKey, $manifest->name, $manifest->description);

        return response()->json([
            'message' => 'Payment module installed.',
            'module' => $module,
        ]);
    }

    public function activate(string $providerKey): JsonResponse
    {
        $module = PaymentGovernanceCrudService::activateModule($providerKey);

        return response()->json([
            'message' => 'Payment module activated.',
            'module' => $module,
        ]);
    }

    public function deactivate(string $providerKey): JsonResponse
    {
        $module = PaymentGovernanceCrudService::deactivateModule($providerKey);

        return response()->json([
            'message' => 'Payment module deactivated.',
            'module' => $module,
        ]);
    }

    public function updateCredentials(string $providerKey, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credentials' => ['required', 'array'],
        ]);

        $module = PaymentGovernanceCrudService::updateModuleCredentials($providerKey, $validated['credentials']);

        return response()->json([
            'message' => 'Credentials updated.',
            'has_credentials' => true,
        ]);
    }

    public function health(string $providerKey): JsonResponse
    {
        $result = PaymentGovernanceCrudService::checkModuleHealth($providerKey);

        return response()->json($result);
    }
}
