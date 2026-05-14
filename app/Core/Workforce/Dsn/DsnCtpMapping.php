<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Markets\MarketRuleSet;

/**
 * Mapping of Leezr contribution codes → official URSSAF CTP codes.
 *
 * Resolution order:
 * 1. MarketRuleSet (domain='dsn_ctp') — versioned, date-scoped rules
 * 2. Static MAP — hardcoded fallback (always available)
 *
 * CTP (Code Type Personnel) are normative references from URSSAF.
 * CSG/CRDS lines have no CTP — they go in DSN bloc S21.G00.78 separately.
 *
 * Sprint 6.6 — ADR-524: versioned CTP via MarketRuleSet
 */
class DsnCtpMapping
{
    /**
     * Static fallback map: leezr_code => [ctp_code, ctp_label, dsn_bloc]
     */
    private const MAP = [
        // CTP 100 — RG CAS GENERAL (maladie, vieillesse déplafonnée, AF, CSA)
        'urssaf_maladie'           => ['100', 'RG CAS GENERAL', 'S21.G00.22'],
        'urssaf_vieillesse_deplaf' => ['100', 'RG CAS GENERAL', 'S21.G00.22'],
        'allocations_familiales'   => ['100', 'RG CAS GENERAL', 'S21.G00.22'],
        'csa'                      => ['100', 'RG CAS GENERAL', 'S21.G00.22'],

        // CTP 100 also includes AT/MP (but rate varies by CARSAT classification)
        'at_mp'                    => ['100', 'RG CAS GENERAL', 'S21.G00.22'],

        // CTP 100 includes plafonnée (T1)
        'urssaf_vieillesse_plaf'   => ['100', 'RG CAS GENERAL', 'S21.G00.22'],

        // CTP 260 — AGIRC-ARRCO Tranche 1 (includes CEG T1 + CET)
        'retraite_t1'              => ['260', 'RET COMPL T1', 'S21.G00.22'],
        'ceg_t1'                   => ['260', 'RET COMPL T1', 'S21.G00.22'],
        'cet'                      => ['260', 'RET COMPL T1', 'S21.G00.22'],

        // CTP 262 — AGIRC-ARRCO Tranche 2 (includes CEG T2)
        'retraite_t2'              => ['262', 'RET COMPL T2', 'S21.G00.22'],
        'ceg_t2'                   => ['262', 'RET COMPL T2', 'S21.G00.22'],

        // CTP 332 — FNAL 50 salariés et plus
        'fnal'                     => ['332', 'FNAL 50 SAL ET PLUS', 'S21.G00.22'],

        // CTP 772 — ASSURANCE CHOMAGE
        'chomage'                  => ['772', 'CONTRIB ASSURANCE CHOMAGE', 'S21.G00.22'],

        // CTP 937 — AGS (garantie des salaires)
        'ags'                      => ['937', 'COTIS AGS', 'S21.G00.22'],

        // CTP 058 — FORMATION PROFESSIONNELLE + TAXE APPRENTISSAGE
        'formation_pro'            => ['058', 'FORM PROF + TAXE APPRENTISSAGE', 'S21.G00.22'],
        'taxe_apprentissage'       => ['058', 'FORM PROF + TAXE APPRENTISSAGE', 'S21.G00.22'],

        // CTP 027 — CONTRIBUTION AU DIALOGUE SOCIAL
        'dialogue_social'          => ['027', 'DIALOGUE SOCIAL', 'S21.G00.22'],

        // CSG/CRDS — no CTP, dedicated DSN bloc S21.G00.78
        'csg_deductible'           => [null, 'CSG DEDUCTIBLE', 'S21.G00.78'],
        'csg_non_deductible'       => [null, 'CSG NON DEDUCTIBLE', 'S21.G00.78'],
        'crds'                     => [null, 'CRDS', 'S21.G00.78'],
    ];

    /**
     * In-memory cache for versioned lookups (per-request).
     * @var array<string, array>|null
     */
    private static ?array $versionedCache = null;

    /**
     * Get CTP info for a Leezr contribution code.
     *
     * Checks MarketRuleSet (domain='dsn_ctp') first, then static fallback.
     *
     * @param  string  $leezrCode
     * @param  string  $marketKey  Market for versioned lookup (default: 'FR')
     * @param  string|null  $atDate  Date for effective_from/until check (default: today)
     * @return array{ctp_code: ?string, ctp_label: string, dsn_bloc: string}|null
     */
    public static function get(string $leezrCode, string $marketKey = 'FR', ?string $atDate = null): ?array
    {
        // 1. Try versioned lookup
        $versioned = self::getVersioned($leezrCode, $marketKey, $atDate);
        if ($versioned !== null) {
            return $versioned;
        }

        // 2. Static fallback
        return self::getStatic($leezrCode);
    }

    /**
     * Get CTP info from static MAP only (no DB).
     */
    public static function getStatic(string $leezrCode): ?array
    {
        $entry = self::MAP[$leezrCode] ?? null;
        if ($entry === null) {
            return null;
        }

        return [
            'ctp_code' => $entry[0],
            'ctp_label' => $entry[1],
            'dsn_bloc' => $entry[2],
        ];
    }

    /**
     * Get CTP info from MarketRuleSet (versioned, date-scoped).
     */
    public static function getVersioned(string $leezrCode, string $marketKey = 'FR', ?string $atDate = null): ?array
    {
        $cache = self::loadVersionedCache($marketKey, $atDate);

        return $cache[$leezrCode] ?? null;
    }

    /**
     * Get CTP code only (null for CSG/CRDS lines).
     */
    public static function ctpCode(string $leezrCode, string $marketKey = 'FR', ?string $atDate = null): ?string
    {
        $info = self::get($leezrCode, $marketKey, $atDate);

        return $info['ctp_code'] ?? null;
    }

    /**
     * Get DSN bloc for a Leezr code.
     */
    public static function dsnBloc(string $leezrCode, string $marketKey = 'FR', ?string $atDate = null): ?string
    {
        $info = self::get($leezrCode, $marketKey, $atDate);

        return $info['dsn_bloc'] ?? null;
    }

    /**
     * Check if a Leezr code has a CTP mapping.
     */
    public static function hasCtp(string $leezrCode, string $marketKey = 'FR', ?string $atDate = null): bool
    {
        $info = self::get($leezrCode, $marketKey, $atDate);

        return $info !== null && $info['ctp_code'] !== null;
    }

    /**
     * Check if a Leezr code is CSG/CRDS (bloc S21.G00.78).
     */
    public static function isCsgCrds(string $leezrCode, string $marketKey = 'FR', ?string $atDate = null): bool
    {
        $info = self::get($leezrCode, $marketKey, $atDate);

        return $info !== null && $info['dsn_bloc'] === 'S21.G00.78';
    }

    /**
     * Get all unique CTP codes (static MAP only — versioned codes are additive).
     * @return string[]
     */
    public static function allCtpCodes(): array
    {
        $codes = [];
        foreach (self::MAP as $entry) {
            if ($entry[0] !== null && ! in_array($entry[0], $codes, true)) {
                $codes[] = $entry[0];
            }
        }

        return $codes;
    }

    /**
     * Get all Leezr codes that map to a given CTP code (static MAP).
     * @return string[]
     */
    public static function leezrCodesForCtp(string $ctpCode): array
    {
        $codes = [];
        foreach (self::MAP as $leezrCode => $entry) {
            if ($entry[0] === $ctpCode) {
                $codes[] = $leezrCode;
            }
        }

        return $codes;
    }

    /**
     * All mapped Leezr codes (static MAP).
     * @return string[]
     */
    public static function allLeezrCodes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Clear the versioned cache (useful in tests).
     */
    public static function clearCache(): void
    {
        self::$versionedCache = null;
    }

    /**
     * Load all active versioned CTP mappings into cache.
     *
     * MarketRuleSet format for domain='dsn_ctp':
     *   rule_key = leezr contribution code (e.g. 'urssaf_maladie')
     *   value    = {ctp_code: '100', ctp_label: 'RG CAS GENERAL', dsn_bloc: 'S21.G00.22'}
     *
     * @return array<string, array{ctp_code: ?string, ctp_label: string, dsn_bloc: string}>
     */
    private static function loadVersionedCache(string $marketKey, ?string $atDate): array
    {
        if (self::$versionedCache !== null) {
            return self::$versionedCache;
        }

        try {
            $rules = MarketRuleSet::where('market_key', $marketKey)
                ->domain('dsn_ctp')
                ->activeAt($atDate)
                ->get();

            $cache = [];
            foreach ($rules as $rule) {
                $value = $rule->value;
                if (isset($value['ctp_label'], $value['dsn_bloc'])) {
                    $cache[$rule->rule_key] = [
                        'ctp_code' => $value['ctp_code'] ?? null,
                        'ctp_label' => $value['ctp_label'],
                        'dsn_bloc' => $value['dsn_bloc'],
                    ];
                }
            }

            self::$versionedCache = $cache;
        } catch (\Throwable) {
            // DB not available (migrations, tests) → empty cache, use static
            self::$versionedCache = [];
        }

        return self::$versionedCache;
    }
}
