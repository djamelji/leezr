<?php

namespace App\Core\Ai\ReadModels;

use App\Core\Ai\AiProviderRegistry;
use App\Core\Ai\PlatformAiModule;

/**
 * Read service for AI provider governance.
 * Merges DB modules with registry manifests (same pattern as PlatformPaymentGovernanceReadService).
 *
 * @see \App\Core\Billing\ReadModels\PlatformPaymentGovernanceReadService
 */
class PlatformAiGovernanceReadService
{
    /**
     * List all AI provider modules (DB rows enriched with registry data).
     */
    public static function listModules(): array
    {
        $dbModules = PlatformAiModule::orderByDesc('sort_order')->get();
        $registryManifests = AiProviderRegistry::all();

        // Start with DB modules, enrich with registry
        $modules = $dbModules->map(function (PlatformAiModule $module) use ($registryManifests) {
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
                    $maskedCredentials[$key] = $value ?? '';
                }
            }

            return [
                'id' => $module->id,
                'provider_key' => $module->provider_key,
                'name' => $module->name,
                'description' => $module->description,
                'is_installed' => $module->is_installed,
                'is_active' => $module->is_active,
                'has_credentials' => ! empty($module->credentials),
                'credentials_masked' => $maskedCredentials,
                'configuration_status' => $module->getConfigurationStatus(),
                'health_status' => $module->health_status,
                'health_checked_at' => $module->health_checked_at?->toIso8601String(),
                'supported_capabilities' => $manifest?->supportedCapabilities ?? [],
                'requires_credentials' => $manifest?->requiresCredentials ?? false,
                'credential_fields' => $manifest?->credentialFields ?? [],
                'icon_ref' => $manifest?->iconRef ?? 'tabler-cpu',
                'in_registry' => $manifest !== null,
            ];
        })->all();

        // Add registry-only manifests not yet in DB
        foreach ($registryManifests as $providerKey => $manifest) {
            $inDb = $dbModules->contains('provider_key', $providerKey);

            if (! $inDb) {
                $modules[] = [
                    'id' => null,
                    'provider_key' => $providerKey,
                    'name' => $manifest->name,
                    'description' => $manifest->description,
                    'is_installed' => false,
                    'is_active' => false,
                    'has_credentials' => false,
                    'credentials_masked' => [],
                    'configuration_status' => 'disabled',
                    'health_status' => 'unknown',
                    'health_checked_at' => null,
                    'supported_capabilities' => $manifest->supportedCapabilities,
                    'requires_credentials' => $manifest->requiresCredentials,
                    'credential_fields' => $manifest->credentialFields,
                    'icon_ref' => $manifest->iconRef,
                    'in_registry' => true,
                ];
            }
        }

        return $modules;
    }
}
