<?php

namespace App\Core\Billing;

/**
 * Immutable value object declaring a payment module's identity and capabilities.
 * Registered in PaymentRegistry, discovered from module.json or built-in.
 */
final class PaymentModuleManifest
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $name,
        public readonly string $description,
        public readonly array $supportedMethods,
        public readonly string $iconRef = 'tabler-credit-card',
        public readonly bool $requiresCredentials = false,
        public readonly array $credentialFields = [],
    ) {}

    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'name' => $this->name,
            'description' => $this->description,
            'supported_methods' => $this->supportedMethods,
            'icon_ref' => $this->iconRef,
            'requires_credentials' => $this->requiresCredentials,
            'credential_fields' => $this->credentialFields,
        ];
    }
}
