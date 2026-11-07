<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * ADR-732 (P0-A) — AUTORITÉ SERVEUR UNIQUE de commercialisation des modules.
 *
 * Un module n'est proposable à l'achat / à l'activation payante QUE si cette autorité le déclare
 * commercialisable. Un prix (`addon_pricing` en base) ne suffit JAMAIS. La décision repose sur la
 * DÉCLARATION de statut du manifeste (`commercialStatus`), maintenable et vérifiable — pas sur une
 * heuristique (nombre de routes/menus). Un module d'infrastructure sans frontend reste légitime ;
 * un module « vendable » sans statut commercialisable ne l'est pas.
 *
 * Consommée par : ModuleCatalogReadModel (ne propose pas le prix) + ModuleActivationEngine (refuse
 * l'activation même si le frontend est contourné). NE touche PAS EntitlementResolver → aucune
 * révocation silencieuse des droits/abonnements déjà attribués (ADR-732, garde-fou §6).
 */
class ModuleCommercialPolicy
{
    /** Statuts autorisant la vente. Bêta EXCLU par défaut : vente seulement sur politique explicite (§3). */
    public const SELLABLE_STATUSES = ['available'];

    /** Statut déclaré du module (défaut 'available' si non précisé dans le manifeste). */
    public static function statusFor(string $key): string
    {
        $m = ModuleRegistry::definitions()[$key] ?? null;

        return $m?->commercialStatus ?? 'available';
    }

    /**
     * Le module est-il proposable à l'achat pour cette company ? Règles cumulatives (toutes requises) :
     *  - manifeste existe, scope 'company', type addon (ni core, ni outillage) ;
     *  - statut déclaré ∈ SELLABLE_STATUSES ;
     *  - commercialement activé en base (is_listed + is_sellable) ;
     *  - prix défini (addon_pricing non nul) ;
     *  - compatible avec le jobdomain de la company.
     */
    public static function isPurchasable(Company $company, string $key): bool
    {
        return self::blockReason($company, $key) === null;
    }

    /** Raison de blocage (pour messages & tests) ; null si réellement proposable. */
    public static function blockReason(Company $company, string $key): ?string
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;
        if (! $manifest) {
            return 'unknown_module';
        }
        if ($manifest->scope !== 'company' || $manifest->type === 'core') {
            return 'not_a_company_addon';
        }
        if (! in_array($manifest->commercialStatus, self::SELLABLE_STATUSES, true)) {
            return 'not_commercialisable';
        }

        $pm = PlatformModule::where('key', $key)->first();
        if (! $pm || ! $pm->is_listed || ! $pm->is_sellable || $pm->addon_pricing === null) {
            return 'not_offered';
        }

        $compatible = $pm->compatible_jobdomains_override ?? $manifest->compatibleJobdomains;
        if ($compatible !== null && ! in_array($company->jobdomain_key, $compatible, true)) {
            return 'incompatible_jobdomain';
        }

        return null;
    }

    /**
     * Invariant d'intégrité (utilisé par les tests) : un module déclaré commercialisable DOIT exposer
     * au moins une capacité réelle (route, item de nav, ou permission). Évite qu'une coquille vide soit
     * déclarée 'available'. Ce n'est PAS la garde runtime (qui repose sur le statut) mais un garde-fou CI.
     */
    public static function declaresRealCapability(string $key): bool
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;
        if (! $manifest) {
            return false;
        }
        $caps = $manifest->capabilities;

        return ! empty($caps->routeNames) || ! empty($caps->navItems) || ! empty($manifest->permissions);
    }
}
