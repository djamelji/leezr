<?php

namespace App\Core\Ai;

use App\Core\Ai\DTOs\AiCapability;

/**
 * Static registry of AI provider manifests.
 * Boots with built-in manifests (null, ollama, openai, anthropic).
 * Mirrors PaymentRegistry.
 *
 * @see \App\Core\Billing\PaymentRegistry
 */
class AiProviderRegistry
{
    /** @var array<string, AiProviderManifest> */
    private static array $manifests = [];

    public static function register(AiProviderManifest $manifest): void
    {
        static::$manifests[$manifest->providerKey] = $manifest;
    }

    /**
     * @return array<string, AiProviderManifest>
     */
    public static function all(): array
    {
        return static::$manifests;
    }

    public static function get(string $providerKey): ?AiProviderManifest
    {
        return static::$manifests[$providerKey] ?? null;
    }

    public static function clearCache(): void
    {
        static::$manifests = [];
    }

    /**
     * Boot all AI provider manifests.
     * Called from AppServiceProvider::boot().
     */
    public static function boot(): void
    {
        $allCapabilities = array_map(fn (AiCapability $c) => $c->value, AiCapability::cases());

        // Null (disabled)
        static::register(new AiProviderManifest(
            providerKey: 'null',
            name: 'Disabled',
            description: 'AI processing disabled — no provider active.',
            supportedCapabilities: [],
            iconRef: 'tabler-ban',
        ));

        // Ollama (self-hosted)
        static::register(new AiProviderManifest(
            providerKey: 'ollama',
            name: 'Ollama',
            description: 'Self-hosted AI inference server. Supports all capabilities via local models.',
            supportedCapabilities: $allCapabilities,
            iconRef: 'tabler-server',
            requiresCredentials: true,
            credentialFields: [
                ['key' => 'host', 'label' => 'Host URL', 'type' => 'text', 'placeholder' => 'http://localhost:11434'],
                ['key' => 'model', 'label' => 'Default Model', 'type' => 'text', 'placeholder' => 'llama3'],
                ['key' => 'vision_model', 'label' => 'Vision Model', 'type' => 'text', 'placeholder' => 'llava'],
                ['key' => 'timeout', 'label' => 'Timeout (seconds)', 'type' => 'number', 'placeholder' => '60'],
            ],
        ));

        // OpenAI (API — catalogue only, no adapter yet)
        static::register(new AiProviderManifest(
            providerKey: 'openai',
            name: 'OpenAI',
            description: 'OpenAI API (GPT-4, GPT-4o). Requires API key.',
            supportedCapabilities: $allCapabilities,
            iconRef: 'tabler-brand-openai',
            requiresCredentials: true,
            credentialFields: [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'sk-...'],
                ['key' => 'organization', 'label' => 'Organization ID', 'type' => 'text', 'placeholder' => 'org-...'],
                ['key' => 'model', 'label' => 'Default Model', 'type' => 'text', 'placeholder' => 'gpt-4o'],
                ['key' => 'timeout', 'label' => 'Timeout (seconds)', 'type' => 'number', 'placeholder' => '60'],
            ],
        ));

        // Anthropic (API — catalogue only, no adapter yet)
        static::register(new AiProviderManifest(
            providerKey: 'anthropic',
            name: 'Anthropic',
            description: 'Anthropic API (Claude). Requires API key.',
            supportedCapabilities: $allCapabilities,
            iconRef: 'tabler-sparkles',
            requiresCredentials: true,
            credentialFields: [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'sk-ant-...'],
                ['key' => 'model', 'label' => 'Default Model', 'type' => 'text', 'placeholder' => 'claude-sonnet-4-5-20250929'],
                ['key' => 'timeout', 'label' => 'Timeout (seconds)', 'type' => 'number', 'placeholder' => '60'],
            ],
        ));
    }
}
