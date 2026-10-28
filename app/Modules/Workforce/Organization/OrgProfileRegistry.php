<?php

namespace App\Modules\Workforce\Organization;

/**
 * ADR-636 (W-E) — catalogue des profils organisationnels (`org_profile_key`) cohérent avec les
 * JobDomains réellement déclarés. Source unique de vérité (pas de switch en dur dans Vue : le front
 * consomme ce catalogue via l'API). Un profil doit appartenir au JobDomain de la Company pour être valide.
 *
 * Seuls les profils de JobDomains RÉELLEMENT enregistrés sont listés (aujourd'hui : `logistique`).
 * restaurant / hair_salon / professional_services attendront la déclaration de leurs JobDomains.
 */
class OrgProfileRegistry
{
    private const PROFILES = [
        'logistique' => [
            ['key' => 'last_mile_delivery', 'label' => 'Livraison dernier kilomètre'],
            ['key' => 'freight_transport', 'label' => 'Transport de marchandises'],
            ['key' => 'warehouse_operations', 'label' => 'Opérations d\'entrepôt'],
        ],
    ];

    /** Profils disponibles pour un JobDomain. */
    public static function forJobdomain(?string $jobdomainKey): array
    {
        return self::PROFILES[$jobdomainKey] ?? [];
    }

    public static function isValid(?string $jobdomainKey, ?string $profileKey): bool
    {
        if ($profileKey === null) {
            return true; // nullable / fallback autorisé
        }

        return collect(self::forJobdomain($jobdomainKey))->contains('key', $profileKey);
    }

    public static function label(?string $jobdomainKey, ?string $profileKey): ?string
    {
        return collect(self::forJobdomain($jobdomainKey))->firstWhere('key', $profileKey)['label'] ?? $profileKey;
    }
}
