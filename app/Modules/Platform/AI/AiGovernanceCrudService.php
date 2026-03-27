<?php

namespace App\Modules\Platform\AI;

use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\PlatformAiModule;
use Illuminate\Validation\ValidationException;

/**
 * Thin CRUD service for AI module operations.
 * Mirrors PaymentGovernanceCrudService — keeps Eloquent out of controllers.
 *
 * @see \App\Modules\Platform\Billing\PaymentGovernanceCrudService
 */
class AiGovernanceCrudService
{
    public static function installModule(string $providerKey, string $name, string $description): PlatformAiModule
    {
        return PlatformAiModule::updateOrCreate(
            ['provider_key' => $providerKey],
            [
                'name' => $name,
                'description' => $description,
                'is_installed' => true,
            ],
        );
    }

    public static function activateModule(string $providerKey): PlatformAiModule
    {
        $module = PlatformAiModule::where('provider_key', $providerKey)->firstOrFail();

        if (! $module->is_installed) {
            throw ValidationException::withMessages([
                'provider_key' => ['Module must be installed before activation.'],
            ]);
        }

        $module->update(['is_active' => true]);

        return $module;
    }

    public static function deactivateModule(string $providerKey): PlatformAiModule
    {
        $module = PlatformAiModule::where('provider_key', $providerKey)->firstOrFail();
        $module->update(['is_active' => false]);

        return $module;
    }

    public static function updateModuleCredentials(string $providerKey, array $credentials): PlatformAiModule
    {
        $module = PlatformAiModule::where('provider_key', $providerKey)->firstOrFail();

        // Merge: preserve existing keys, keep originals when submitted value is masked
        $existing = $module->credentials ?? [];
        $merged = $existing;

        foreach ($credentials as $key => $value) {
            if (is_string($value) && str_contains($value, '••••') && isset($existing[$key])) {
                $merged[$key] = $existing[$key]; // Keep original (masked = unchanged)
            } elseif ($value === '' && isset($existing[$key]) && $existing[$key] !== '') {
                $merged[$key] = $existing[$key]; // Keep existing when field submitted empty
            } else {
                $merged[$key] = $value; // Use new value
            }
        }

        // Use setter + save() — update() can bypass the encrypted:array cast
        $module->credentials = $merged;
        $module->save();

        return $module;
    }

    public static function checkModuleHealth(string $providerKey): array
    {
        $module = PlatformAiModule::where('provider_key', $providerKey)->firstOrFail();
        $adapter = AiGatewayManager::adapterFor($providerKey);

        if (! $adapter) {
            throw ValidationException::withMessages([
                'provider_key' => ['No adapter available for this provider.'],
            ]);
        }

        $result = $adapter->healthCheck();

        $module->update([
            'health_status' => $result->status,
            'health_checked_at' => now(),
        ]);

        return [
            'health' => $result->toArray(),
            'checked_at' => now()->toISOString(),
        ];
    }
}
