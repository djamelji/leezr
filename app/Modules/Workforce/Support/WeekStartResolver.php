<?php

namespace App\Modules\Workforce\Support;

use App\Core\Markets\Market;
use App\Core\Models\Company;

/**
 * W-G4B (ADR-645) — résolution du premier jour de semaine, SANS jamais coder lundi/dimanche en dur.
 *
 * Priorité : préférence EXPLICITE Company (`week_start_day`) → convention du Market → repli locale.
 * Convention : 0 = dimanche … 6 = samedi (getDay JS / ISO côté front).
 */
class WeekStartResolver
{
    /** Marchés à semaine commençant le dimanche (anglo-saxons). Tout le reste : lundi. */
    private const SUNDAY_FIRST_MARKETS = ['US', 'CA'];
    private const SUNDAY_FIRST_LOCALES = ['en-US', 'en-CA'];

    public static function resolve(Company $company): int
    {
        // 1. Préférence explicite Company.
        if ($company->week_start_day !== null) {
            return (int) $company->week_start_day;
        }

        // 2. Convention du Market.
        $market = $company->market_key ? Market::where('key', $company->market_key)->first() : null;
        if ($market) {
            if (in_array($market->key, self::SUNDAY_FIRST_MARKETS, true)) {
                return 0;
            }
            if (in_array($market->locale, self::SUNDAY_FIRST_LOCALES, true)) {
                return 0;
            }

            return 1; // Europe & reste : lundi
        }

        // 3. Repli : lundi (ISO) — jamais présumé en dur ailleurs qu'ici, en dernier recours.
        return 1;
    }

    /**
     * @return array{week_start_day:int, source:string, editable:bool}
     */
    public static function describe(Company $company, bool $canEdit): array
    {
        return [
            'week_start_day' => self::resolve($company),
            'source' => $company->week_start_day !== null ? 'company' : 'market',
            'editable' => $canEdit,
        ];
    }
}
