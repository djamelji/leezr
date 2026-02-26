<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\ReadModels\PlatformPaymentGovernanceReadService;
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

        if (!$manifest) {
            return response()->json(['message' => 'Unknown payment provider.'], 404);
        }

        $module = PlatformPaymentModule::updateOrCreate(
            ['provider_key' => $providerKey],
            [
                'name' => $manifest->name,
                'description' => $manifest->description,
                'is_installed' => true,
            ],
        );

        return response()->json([
            'message' => 'Payment module installed.',
            'module' => $module,
        ]);
    }

    public function activate(string $providerKey): JsonResponse
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->first();

        if (!$module) {
            return response()->json(['message' => 'Payment module not found.'], 404);
        }

        if (!$module->is_installed) {
            return response()->json(['message' => 'Module must be installed before activation.'], 422);
        }

        $module->update(['is_active' => true]);

        return response()->json([
            'message' => 'Payment module activated.',
            'module' => $module,
        ]);
    }

    public function deactivate(string $providerKey): JsonResponse
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->first();

        if (!$module) {
            return response()->json(['message' => 'Payment module not found.'], 404);
        }

        $module->update(['is_active' => false]);

        return response()->json([
            'message' => 'Payment module deactivated.',
            'module' => $module,
        ]);
    }

    public function updateCredentials(string $providerKey, Request $request): JsonResponse
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->first();

        if (!$module) {
            return response()->json(['message' => 'Payment module not found.'], 404);
        }

        $validated = $request->validate([
            'credentials' => ['required', 'array'],
        ]);

        $module->update(['credentials' => $validated['credentials']]);

        return response()->json([
            'message' => 'Credentials updated.',
            'has_credentials' => true,
        ]);
    }

    public function health(string $providerKey): JsonResponse
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->first();

        if (!$module) {
            return response()->json(['message' => 'Payment module not found.'], 404);
        }

        $adapter = static::resolveAdapter($providerKey);

        if (!$adapter) {
            return response()->json(['message' => 'No adapter available for this provider.'], 422);
        }

        $result = $adapter->healthCheck();

        $module->update([
            'health_status' => $result->status,
            'health_checked_at' => now(),
        ]);

        return response()->json([
            'health' => $result->toArray(),
            'checked_at' => now()->toISOString(),
        ]);
    }

    private static function resolveAdapter(string $providerKey): ?\App\Core\Billing\Contracts\PaymentProviderAdapter
    {
        return match ($providerKey) {
            'internal' => new InternalPaymentAdapter(),
            'stripe' => new StripePaymentAdapter(),
            default => null,
        };
    }
}
