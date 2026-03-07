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

        $credentials = $validated['credentials'];

        // Stripe-specific key format validation
        if ($providerKey === 'stripe') {
            $errors = static::validateStripeKeyFormats($credentials);

            if (! empty($errors)) {
                return response()->json(['message' => implode(' ', $errors)], 422);
            }
        }

        $module = PaymentGovernanceCrudService::updateModuleCredentials($providerKey, $credentials);

        return response()->json([
            'message' => 'Credentials updated.',
            'has_credentials' => true,
        ]);
    }

    private static function validateStripeKeyFormats(array $creds): array
    {
        $errors = [];
        $mode = $creds['mode'] ?? 'test';

        $checks = [
            'test' => [
                'test_publishable_key' => 'pk_test_',
                'test_secret_key' => 'sk_test_',
            ],
            'live' => [
                'live_publishable_key' => 'pk_live_',
                'live_secret_key' => 'sk_live_',
            ],
        ];

        foreach ($checks[$mode] ?? [] as $field => $prefix) {
            $value = $creds[$field] ?? '';

            // Skip masked values (user didn't change them)
            if (str_contains($value, '••••') || $value === '') {
                continue;
            }

            if (! str_starts_with($value, $prefix)) {
                $errors[] = "{$field} must start with {$prefix}.";
            }
        }

        return $errors;
    }

    public function health(string $providerKey): JsonResponse
    {
        $result = PaymentGovernanceCrudService::checkModuleHealth($providerKey);

        return response()->json($result);
    }
}
