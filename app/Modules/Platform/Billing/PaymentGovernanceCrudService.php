<?php

namespace App\Modules\Platform\Billing;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use Illuminate\Validation\ValidationException;

/**
 * Thin CRUD service for payment module + payment method rule operations.
 * No complex invariants — just keeps Eloquent out of controllers.
 */
class PaymentGovernanceCrudService
{
    // ── Payment Modules ──────────────────────────────

    public static function installModule(string $providerKey, string $name, string $description): PlatformPaymentModule
    {
        return PlatformPaymentModule::updateOrCreate(
            ['provider_key' => $providerKey],
            [
                'name' => $name,
                'description' => $description,
                'is_installed' => true,
            ],
        );
    }

    public static function activateModule(string $providerKey): PlatformPaymentModule
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->firstOrFail();

        if (! $module->is_installed) {
            throw ValidationException::withMessages([
                'provider_key' => ['Module must be installed before activation.'],
            ]);
        }

        $module->update(['is_active' => true]);

        return $module;
    }

    public static function deactivateModule(string $providerKey): PlatformPaymentModule
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->firstOrFail();
        $module->update(['is_active' => false]);

        return $module;
    }

    public static function updateModuleCredentials(string $providerKey, array $credentials): PlatformPaymentModule
    {
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->firstOrFail();

        // Merge: preserve existing keys, keep originals when submitted value is masked
        $existing = $module->credentials ?? [];
        $merged = $existing; // Start with ALL existing keys (preserve unsubmitted ones)

        foreach ($credentials as $key => $value) {
            if (is_string($value) && str_contains($value, '••••') && isset($existing[$key])) {
                $merged[$key] = $existing[$key]; // Keep original (masked = unchanged)
            } elseif ($value === '' && isset($existing[$key]) && $existing[$key] !== '') {
                $merged[$key] = $existing[$key]; // Keep existing when field submitted empty but has a value
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
        $module = PlatformPaymentModule::where('provider_key', $providerKey)->firstOrFail();
        $adapter = static::resolveAdapter($providerKey);

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

    // ── Payment Method Rules ─────────────────────────

    public static function createRule(array $validated): PlatformPaymentMethodRule
    {
        return PlatformPaymentMethodRule::create($validated);
    }

    public static function updateRule(int $id, array $validated): PlatformPaymentMethodRule
    {
        $rule = PlatformPaymentMethodRule::findOrFail($id);
        $rule->update($validated);

        return $rule->fresh();
    }

    public static function deleteRule(int $id): void
    {
        PlatformPaymentMethodRule::findOrFail($id)->delete();
    }

    // ── Internal ─────────────────────────────────────

    private static function resolveAdapter(string $providerKey): ?PaymentProviderAdapter
    {
        return match ($providerKey) {
            'internal' => new InternalPaymentAdapter(),
            'stripe' => new StripePaymentAdapter(),
            default => null,
        };
    }
}
