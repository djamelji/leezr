<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;

/**
 * W-SIGNATURE-1 (ADR-651) — capacités de signature par Market (§6).
 *
 * La disponibilité d'un niveau dépend du Market ET du prestataire ET de ses capacités RÉELLEMENT
 * vérifiées. Un niveau n'est jamais une simple étiquette UI : un prestataire incapable de produire
 * la preuve requise ne permet pas de le sélectionner.
 */
class SignatureLevelCatalog
{
    /**
     * Niveaux disponibles pour (market, provider), issus des capacités réelles de l'adaptateur.
     * @return string[]
     */
    public static function availableLevels(string $marketKey, ?string $providerKey): array
    {
        $adapter = SignatureProviderManager::adapterFor($providerKey);
        if ($adapter === null) {
            return [];
        }

        // Intersection ordre canonique (simple → advanced → qualified) ∩ capacités prestataire.
        return array_values(array_filter(
            SignatureRequest::LEVELS,
            fn (string $level) => $adapter->supportsLevel($marketKey, $level),
        ));
    }

    public static function supports(string $marketKey, ?string $providerKey, string $level): bool
    {
        return in_array($level, self::availableLevels($marketKey, $providerKey), true);
    }

    /**
     * Métadonnée UI/API : la liste des niveaux avec un indicateur de certification du prestataire.
     * @return array<int,array{level:string,certified:bool}>
     */
    public static function describe(string $marketKey, ?string $providerKey): array
    {
        $adapter = SignatureProviderManager::adapterFor($providerKey);
        if ($adapter === null) {
            return [];
        }
        $certified = $adapter->isCertified();

        return array_map(
            fn (string $level) => ['level' => $level, 'certified' => $certified],
            self::availableLevels($marketKey, $providerKey),
        );
    }
}
