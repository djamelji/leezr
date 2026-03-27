<?php

namespace App\Core\Ai;

use App\Core\Ai\Contracts\AiProviderAdapter;
use App\Core\Ai\DTOs\AiCapability;
use App\Platform\Models\PlatformSetting;
use Illuminate\Support\Manager;

/**
 * AI gateway driver manager.
 * Reads active driver from platform_settings.ai (DB-configurable).
 * Fallback to 'null' driver when no config or during migrations.
 *
 * Pattern: mirrors PaymentGatewayManager exactly.
 *
 * @see \App\Core\Billing\PaymentGatewayManager
 */
class AiGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        try {
            $settings = PlatformSetting::instance();
            $driver = $settings->ai['driver'] ?? null;

            if ($driver) {
                return $driver;
            }

            // Fallback to active PlatformAiModule provider
            $activeModule = PlatformAiModule::active()
                ->orderByDesc('sort_order')
                ->first();

            return $activeModule?->provider_key ?? 'null';
        } catch (\Throwable) {
            return 'null';
        }
    }

    protected function createNullDriver(): Adapters\NullAiAdapter
    {
        return new Adapters\NullAiAdapter();
    }

    protected function createOllamaDriver(): Adapters\OllamaAiAdapter
    {
        return app(Adapters\OllamaAiAdapter::class);
    }

    protected function createAnthropicDriver(): Adapters\AnthropicAiAdapter
    {
        return app(Adapters\AnthropicAiAdapter::class);
    }

    /**
     * Resolve adapter by provider key.
     *
     * @see PaymentGatewayManager::adapterFor()
     */
    public static function adapterFor(string $providerKey): ?AiProviderAdapter
    {
        try {
            $driver = app(self::class)->driver($providerKey);

            return $driver instanceof AiProviderAdapter ? $driver : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Find the first active adapter that supports a given capability.
     * Falls back to null adapter if none found.
     */
    public static function adapterForCapability(AiCapability $capability): AiProviderAdapter
    {
        try {
            $activeModules = PlatformAiModule::active()
                ->orderByDesc('sort_order')
                ->get();

            foreach ($activeModules as $module) {
                $adapter = self::adapterFor($module->provider_key);
                if ($adapter && in_array($capability, $adapter->capabilities())) {
                    return $adapter;
                }
            }
        } catch (\Throwable) {
            // Fall through to null
        }

        return new Adapters\NullAiAdapter();
    }

    /**
     * List available AI providers from active PlatformAiModule records.
     *
     * @return array<array{key: string, name: string, is_active: bool}>
     */
    public static function availableProviders(): array
    {
        try {
            return PlatformAiModule::query()
                ->orderByDesc('sort_order')
                ->get()
                ->map(fn (PlatformAiModule $m) => [
                    'key' => $m->provider_key,
                    'name' => $m->name,
                    'is_active' => $m->is_active && $m->is_installed,
                    'health_status' => $m->health_status,
                    'health_checked_at' => $m->health_checked_at?->toIso8601String(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
