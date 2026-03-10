<?php

namespace App\Core\Markets;

use App\Core\Markets\SvgSanitizer;

/**
 * DB-backed market registry with in-memory cache.
 * Follows PlanRegistry/ModuleRegistry/JobdomainRegistry sync pattern.
 *
 * seedDefaults() holds hardcoded market data for seeding.
 * sync() upserts seed data into markets + legal_statuses + languages + pivots.
 * All runtime methods read from DB via Market model.
 *
 * ADR-104: International Market Engine.
 */
class MarketRegistry
{
    /** @var array<string, Market>|null In-memory cache keyed by market key */
    private static ?array $cache = null;

    /**
     * Hardcoded seed defaults — used by sync() only.
     */
    public static function seedDefaults(): array
    {
        return [
            'languages' => [
                'en' => ['name' => 'English', 'native_name' => 'English', 'is_default' => true, 'sort_order' => 0],
                'fr' => ['name' => 'French', 'native_name' => 'Français', 'is_default' => false, 'sort_order' => 1],
            ],
            'markets' => [
                'FR' => [
                    'name' => 'France',
                    'currency' => 'EUR',
                    'vat_rate_bps' => 2000,
                    'locale' => 'fr-FR',
                    'timezone' => 'Europe/Paris',
                    'dial_code' => '+33',
                    'flag_code' => 'FR',
                    'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><g fill-rule="evenodd" stroke-width="1pt"><path fill="#fff" d="M0 0h640v480H0z"/><path fill="#002654" d="M0 0h213.3v480H0z"/><path fill="#ce1126" d="M426.7 0H640v480H426.7z"/></g></svg>',
                    'is_active' => true,
                    'is_default' => true,
                    'is_eu' => true,
                    'sort_order' => 0,
                    'languages' => ['fr', 'en'],
                    'legal_statuses' => [
                        ['key' => 'sas', 'name' => 'SAS', 'description' => 'Société par Actions Simplifiée', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => true, 'sort_order' => 0],
                        ['key' => 'sasu', 'name' => 'SASU', 'description' => 'SAS Unipersonnelle', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 1],
                        ['key' => 'sarl', 'name' => 'SARL', 'description' => 'Société à Responsabilité Limitée', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 2],
                        ['key' => 'eurl', 'name' => 'EURL', 'description' => 'Entreprise Unipersonnelle à Responsabilité Limitée', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 3],
                        ['key' => 'sa', 'name' => 'SA', 'description' => 'Société Anonyme', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 4],
                        ['key' => 'snc', 'name' => 'SNC', 'description' => 'Société en Nom Collectif', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 5],
                        ['key' => 'sci', 'name' => 'SCI', 'description' => 'Société Civile Immobilière', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 6],
                        ['key' => 'ae', 'name' => 'Auto-entrepreneur', 'description' => 'Micro-entreprise (franchise en base de TVA)', 'is_vat_applicable' => false, 'vat_rate' => null, 'is_default' => false, 'sort_order' => 7],
                    ],
                ],
                'GB' => [
                    'name' => 'United Kingdom',
                    'currency' => 'GBP',
                    'vat_rate_bps' => 2000,
                    'locale' => 'en-GB',
                    'timezone' => 'Europe/London',
                    'dial_code' => '+44',
                    'flag_code' => 'GB',
                    'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><path fill="#012169" d="M0 0h640v480H0z"/><path fill="#FFF" d="m75 0 244 181L562 0h78v62L400 241l240 178v61h-80L320 301 81 480H0v-60l239-178L0 64V0z"/><path fill="#C8102E" d="m424 281 216 159v40L369 281zm-184 20 6 35L54 480H0zM640 0v3L391 191l2-44L590 0zM0 0l239 176h-60L0 42z"/><path fill="#FFF" d="M241 0v480h160V0zM0 160v160h640V160z"/><path fill="#C8102E" d="M0 193v96h640v-96zM273 0v480h96V0z"/></svg>',
                    'is_active' => true,
                    'is_default' => false,
                    'is_eu' => false,
                    'sort_order' => 1,
                    'languages' => ['en'],
                    'legal_statuses' => [
                        ['key' => 'ltd', 'name' => 'Ltd', 'description' => 'Private Limited Company', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => true, 'sort_order' => 0],
                        ['key' => 'plc', 'name' => 'PLC', 'description' => 'Public Limited Company', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 1],
                        ['key' => 'llp', 'name' => 'LLP', 'description' => 'Limited Liability Partnership', 'is_vat_applicable' => true, 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 2],
                        ['key' => 'sole_trader', 'name' => 'Sole Trader', 'description' => 'Self-employed individual', 'is_vat_applicable' => false, 'vat_rate' => null, 'is_default' => false, 'sort_order' => 3],
                    ],
                ],
            ],
        ];
    }

    /**
     * Sync seed defaults to DB. Called from SystemSeeder.
     * Idempotent — safe to run multiple times.
     * Does NOT overwrite is_active/is_default (preserves admin decisions).
     */
    public static function sync(): void
    {
        $defaults = static::seedDefaults();

        // Sync languages first (markets reference them)
        foreach ($defaults['languages'] as $key => $lang) {
            Language::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $lang['name'],
                    'native_name' => $lang['native_name'],
                    'sort_order' => $lang['sort_order'] ?? 0,
                    // Preserve admin decisions for is_active/is_default
                    'is_active' => Language::where('key', $key)->value('is_active') ?? ($lang['is_active'] ?? true),
                    'is_default' => Language::where('key', $key)->value('is_default') ?? ($lang['is_default'] ?? false),
                ],
            );
        }

        // Sync markets
        foreach ($defaults['markets'] as $key => $def) {
            $existingMarket = Market::where('key', $key)->first();

            $flagData = [];
            if (!$existingMarket || empty($existingMarket->flag_code)) {
                $flagData['flag_code'] = $def['flag_code'] ?? null;
            }
            if (!$existingMarket || empty($existingMarket->flag_svg)) {
                $flagData['flag_svg'] = isset($def['flag_svg']) ? SvgSanitizer::sanitize($def['flag_svg']) : null;
            }

            $market = Market::updateOrCreate(
                ['key' => $key],
                array_merge([
                    'name' => $def['name'],
                    'currency' => $def['currency'],
                    'vat_rate_bps' => $def['vat_rate_bps'] ?? 0,
                    'locale' => $def['locale'],
                    'timezone' => $def['timezone'],
                    'dial_code' => $def['dial_code'],
                    'sort_order' => $def['sort_order'] ?? 0,
                    'is_active' => ($existingMarket?->is_active) ?? ($def['is_active'] ?? true),
                    'is_default' => ($existingMarket?->is_default) ?? ($def['is_default'] ?? false),
                    'is_eu' => $def['is_eu'] ?? false,
                ], $flagData),
            );

            // Sync legal statuses for this market
            foreach ($def['legal_statuses'] ?? [] as $ls) {
                $isVatApplicable = $ls['is_vat_applicable'] ?? true;

                LegalStatus::updateOrCreate(
                    ['market_key' => $key, 'key' => $ls['key']],
                    [
                        'name' => $ls['name'],
                        'description' => $ls['description'] ?? null,
                        'is_vat_applicable' => $isVatApplicable,
                        'vat_rate' => $isVatApplicable ? ($ls['vat_rate'] ?? 0) : null,
                        'sort_order' => $ls['sort_order'] ?? 0,
                        'is_default' => LegalStatus::where('market_key', $key)
                            ->where('key', $ls['key'])
                            ->value('is_default') ?? ($ls['is_default'] ?? false),
                    ],
                );
            }

            // Sync market ↔ language pivots
            if (!empty($def['languages'])) {
                $languageKeys = Language::whereIn('key', $def['languages'])->pluck('key')->all();
                $market->languages()->syncWithoutDetaching(
                    array_combine($languageKeys, array_fill(0, count($languageKeys), []))
                );
            }
        }

        static::clearCache();
    }

    /**
     * All active market definitions from DB, keyed by market key.
     * Cached per request.
     */
    public static function definitions(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        try {
            $markets = Market::active()
                ->with(['legalStatuses', 'languages'])
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable) {
            static::$cache = [];

            return [];
        }

        $definitions = [];

        foreach ($markets as $market) {
            $definitions[$market->key] = $market;
        }

        static::$cache = $definitions;

        return $definitions;
    }

    /**
     * Get the default market (is_default=true or first active).
     */
    public static function defaultMarket(): ?Market
    {
        $defs = static::definitions();

        foreach ($defs as $market) {
            if ($market->is_default) {
                return $market;
            }
        }

        return !empty($defs) ? reset($defs) : null;
    }

    /**
     * All valid market keys (active markets only).
     */
    public static function keys(): array
    {
        return array_keys(static::definitions());
    }

    /**
     * Import markets from user-provided array (same shape as export).
     * Used by platform admin import. Upserts markets + legal statuses + language pivots.
     *
     * @return array{created: int, updated: int}
     */
    public static function importFromArray(array $data): array
    {
        $created = 0;
        $updated = 0;

        // Filter out metadata keys
        $data = array_filter($data, fn ($key) => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, &$created, &$updated) {
            foreach ($data as $key => $def) {
                $exists = Market::where('key', $key)->exists();

                $marketData = [
                    'name' => $def['name'],
                    'currency' => $def['currency'] ?? 'USD',
                    'vat_rate_bps' => $def['vat_rate_bps'] ?? 0,
                    'locale' => $def['locale'] ?? 'en-US',
                    'timezone' => $def['timezone'] ?? 'UTC',
                    'dial_code' => $def['dial_code'] ?? '+1',
                    'sort_order' => $def['sort_order'] ?? 0,
                    'is_active' => $def['is_active'] ?? true,
                    'is_default' => $def['is_default'] ?? false,
                ];

                if (!empty($def['flag_code'])) {
                    $marketData['flag_code'] = $def['flag_code'];
                }
                if (!empty($def['flag_svg'])) {
                    $marketData['flag_svg'] = SvgSanitizer::sanitize($def['flag_svg']);
                }

                $market = Market::updateOrCreate(
                    ['key' => $key],
                    $marketData,
                );

                $exists ? $updated++ : $created++;

                // Legal statuses
                foreach ($def['legal_statuses'] ?? [] as $ls) {
                    $isVatApplicable = $ls['is_vat_applicable'] ?? true;

                    LegalStatus::updateOrCreate(
                        ['market_key' => $key, 'key' => $ls['key']],
                        [
                            'name' => $ls['name'],
                            'description' => $ls['description'] ?? null,
                            'is_vat_applicable' => $isVatApplicable,
                            'vat_rate' => $isVatApplicable ? ($ls['vat_rate'] ?? 0) : null,
                            'sort_order' => $ls['sort_order'] ?? 0,
                            'is_default' => $ls['is_default'] ?? false,
                        ],
                    );
                }

                // Language pivots
                if (!empty($def['languages'])) {
                    $languageKeys = Language::whereIn('key', $def['languages'])->pluck('key')->all();

                    if (!empty($languageKeys)) {
                        $market->languages()->syncWithoutDetaching(
                            array_combine($languageKeys, array_fill(0, count($languageKeys), []))
                        );
                    }
                }
            }
        });

        static::clearCache();

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Clear the in-memory cache.
     */
    public static function clearCache(): void
    {
        static::$cache = null;
    }
}
