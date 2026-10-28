<?php

namespace App\Modules\Workforce\Organization;

use App\Core\Models\Company;

/**
 * ADR-636 (W-E) — résolution du preset applicable à une Company, SANS effet de bord.
 *
 * Couches (du plus général au plus spécifique) : jobdomain seul → + market → + profil. On ne mélange
 * jamais : on choisit le preset PUBLIÉ le plus spécifique correspondant à (jobdomain, market?, profil?),
 * à version la plus élevée. Les règles légales impératives ne vivent PAS ici (elles restent dans
 * MarketRuleSet / conventions, hors de ce catalogue structurel).
 */
class OrganizationPresetResolver
{
    public static function resolveFor(Company $company): ?OrganizationPreset
    {
        return static::resolve($company->jobdomain_key, $company->market_key, $company->org_profile_key);
    }

    public static function resolve(?string $jobdomainKey, ?string $marketKey, ?string $orgProfileKey): ?OrganizationPreset
    {
        if (! $jobdomainKey) {
            return null;
        }

        $candidates = OrganizationPreset::query()
            ->published()
            ->where('jobdomain_key', $jobdomainKey)
            ->where(fn ($q) => $q->whereNull('market_key')->orWhere('market_key', $marketKey))
            ->where(fn ($q) => $q->whereNull('org_profile_key')->orWhere('org_profile_key', $orgProfileKey))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Spécificité : profil (4) + market (2). À égalité, version la plus élevée. Score entier pour
        // un tri déterministe (profil > market > version).
        return $candidates
            ->sortByDesc(function (OrganizationPreset $p) use ($orgProfileKey, $marketKey) {
                $score = 0;
                if ($orgProfileKey !== null && $p->org_profile_key === $orgProfileKey) {
                    $score += 4;
                }
                if ($marketKey !== null && $p->market_key === $marketKey) {
                    $score += 2;
                }

                return $score * 100000 + $p->version;
            })
            ->first();
    }
}
