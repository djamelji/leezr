<?php

namespace App\Core\Modules;

/**
 * Immutable value object representing a module's full definition.
 *
 * Replaces the raw associative arrays previously returned by ModuleRegistry.
 * All consumers access typed properties instead of fragile array keys.
 *
 * scope: 'admin' (platform admin portal) | 'company' (tenant portal).
 * surface: 'structure' (governance UI) | 'operations' (business UI).
 */
final class ModuleManifest
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $surface,
        public readonly int $sortOrder,
        public readonly Capabilities $capabilities,
        public readonly array $permissions,
        public readonly array $bundles,
        public readonly string $scope,
        public readonly string $type = 'core',
        public readonly string $visibility = 'visible',
        public readonly array $requires = [],
        public readonly ?string $minPlan = null,
        public readonly ?array $compatibleJobdomains = null,
        public readonly string $iconType = 'tabler',
        public readonly string $iconRef = 'tabler-puzzle',
    ) {}
}
