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
 *
 * commercialStatus (ADR-732, P0-A) — DÉCLARATION maintenable de l'état produit, source de vérité
 * de la commercialisation (indépendante du prix en base). Valeurs :
 *   'available'    — fonctionnalité livrée, commercialisable ;
 *   'beta'         — livrée en bêta ; NON vendue sans politique explicite (jamais achetable par défaut) ;
 *   'preview'      — « En préparation », déclarée mais non livrée → JAMAIS proposable à l'achat ;
 *   'internal'     — outillage interne, hors commerce ;
 *   'demo'         — démonstration ;
 *   'deprecated'   — obsolète/legacy.
 * Un prix (`addon_pricing`) ne rend JAMAIS un module achetable : seule l'autorité serveur
 * `ModuleCommercialPolicy` (statut 'available' + règles) l'autorise.
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
        public readonly ?string $settingsRoute = null,
        public readonly bool $hidden = false,
        public readonly array $marketplace = [],
        public readonly string $commercialStatus = 'available',
    ) {}
}
