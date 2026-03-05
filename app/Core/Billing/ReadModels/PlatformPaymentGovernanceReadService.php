<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\PaymentOrchestrator;
use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;

class PlatformPaymentGovernanceReadService
{
    /**
     * List all payment modules (DB rows enriched with registry data).
     */
    public static function listModules(): array
    {
        $dbModules = PlatformPaymentModule::orderBy('sort_order')->get();
        $registryManifests = PaymentRegistry::all();

        // Start with DB modules, enrich with registry
        $modules = $dbModules->map(function (PlatformPaymentModule $module) use ($registryManifests) {
            $manifest = $registryManifests[$module->provider_key] ?? null;

            // Mask credential values for display (show first 8 + last 4 chars)
            $maskedCredentials = [];
            $rawCredentials = $module->credentials ?? [];
            foreach ($rawCredentials as $key => $value) {
                if (is_string($value) && strlen($value) > 12) {
                    $maskedCredentials[$key] = substr($value, 0, 8) . '••••' . substr($value, -4);
                } elseif (is_string($value) && strlen($value) > 0) {
                    $maskedCredentials[$key] = '••••••••';
                } else {
                    $maskedCredentials[$key] = '';
                }
            }

            return [
                'id' => $module->id,
                'provider_key' => $module->provider_key,
                'name' => $module->name,
                'description' => $module->description,
                'is_installed' => $module->is_installed,
                'is_active' => $module->is_active,
                'has_credentials' => !empty($module->credentials),
                'credentials_masked' => $maskedCredentials,
                'health_status' => $module->health_status,
                'health_checked_at' => $module->health_checked_at?->toISOString(),
                'sort_order' => $module->sort_order,
                'supported_methods' => $manifest?->supportedMethods ?? [],
                'requires_credentials' => $manifest?->requiresCredentials ?? false,
                'credential_fields' => $manifest?->credentialFields ?? [],
                'icon_ref' => $manifest?->iconRef ?? 'tabler-credit-card',
                'in_registry' => $manifest !== null,
            ];
        })->all();

        // Add registry-only manifests not yet in DB
        foreach ($registryManifests as $providerKey => $manifest) {
            $inDb = $dbModules->contains('provider_key', $providerKey);

            if (!$inDb) {
                $modules[] = [
                    'id' => null,
                    'provider_key' => $providerKey,
                    'name' => $manifest->name,
                    'description' => $manifest->description,
                    'is_installed' => false,
                    'is_active' => false,
                    'has_credentials' => false,
                    'health_status' => 'unknown',
                    'health_checked_at' => null,
                    'sort_order' => 999,
                    'supported_methods' => $manifest->supportedMethods,
                    'requires_credentials' => $manifest->requiresCredentials,
                    'credential_fields' => $manifest->credentialFields,
                    'icon_ref' => $manifest->iconRef,
                    'in_registry' => true,
                ];
            }
        }

        return $modules;
    }

    /**
     * List all payment method rules.
     */
    public static function listRules(array $filters = []): array
    {
        $query = PlatformPaymentMethodRule::query();

        if (!empty($filters['provider_key'])) {
            $query->where('provider_key', $filters['provider_key']);
        }

        if (!empty($filters['method_key'])) {
            $query->where('method_key', $filters['method_key']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->orderBy('priority', 'desc')
            ->get()
            ->map(fn (PlatformPaymentMethodRule $rule) => [
                'id' => $rule->id,
                'method_key' => $rule->method_key,
                'provider_key' => $rule->provider_key,
                'market_key' => $rule->market_key,
                'plan_key' => $rule->plan_key,
                'interval' => $rule->interval,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'constraints' => $rule->constraints,
            ])
            ->all();
    }

    /**
     * Preview resolved methods for a given context.
     */
    public static function previewMethods(
        ?string $marketKey = null,
        ?string $planKey = null,
        ?string $interval = null,
    ): array {
        return PaymentOrchestrator::previewMethodsForContext($marketKey, $planKey, $interval);
    }
}
