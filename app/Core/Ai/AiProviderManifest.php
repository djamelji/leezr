<?php

namespace App\Core\Ai;

/**
 * Immutable value object declaring an AI provider's identity and capabilities.
 * Registered in AiProviderRegistry, mirrors PaymentModuleManifest.
 *
 * @see \App\Core\Billing\PaymentModuleManifest
 */
final class AiProviderManifest
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $name,
        public readonly string $description,
        public readonly array $supportedCapabilities,
        public readonly string $iconRef = 'tabler-cpu',
        public readonly bool $requiresCredentials = false,
        public readonly array $credentialFields = [],
    ) {}

    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'name' => $this->name,
            'description' => $this->description,
            'supported_capabilities' => $this->supportedCapabilities,
            'icon_ref' => $this->iconRef,
            'requires_credentials' => $this->requiresCredentials,
            'credential_fields' => $this->credentialFields,
        ];
    }
}
