<?php

namespace App\Core\Markets;

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
                    'locale' => 'fr-FR',
                    'timezone' => 'Europe/Paris',
                    'dial_code' => '+33',
                    'is_active' => true,
                    'is_default' => true,
                    'sort_order' => 0,
                    'languages' => ['fr', 'en'],
                    'legal_statuses' => [
                        ['key' => 'sas', 'name' => 'SAS', 'description' => 'Société par Actions Simplifiée', 'vat_rate' => 20.00, 'is_default' => true, 'sort_order' => 0],
                        ['key' => 'sasu', 'name' => 'SASU', 'description' => 'SAS Unipersonnelle', 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 1],
                        ['key' => 'sarl', 'name' => 'SARL', 'description' => 'Société à Responsabilité Limitée', 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 2],
                        ['key' => 'eurl', 'name' => 'EURL', 'description' => 'Entreprise Unipersonnelle à Responsabilité Limitée', 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 3],
                        ['key' => 'sa', 'name' => 'SA', 'description' => 'Société Anonyme', 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 4],
                        ['key' => 'snc', 'name' => 'SNC', 'description' => 'Société en Nom Collectif', 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 5],
                        ['key' => 'sci', 'name' => 'SCI', 'description' => 'Société Civile Immobilière', 'vat_rate' => 20.00, 'is_default' => false, 'sort_order' => 6],
                        ['key' => 'ae', 'name' => 'Auto-entrepreneur', 'description' => 'Micro-entreprise', 'vat_rate' => 0, 'is_default' => false, 'sort_order' => 7],
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
            $market = Market::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $def['name'],
                    'currency' => $def['currency'],
                    'locale' => $def['locale'],
                    'timezone' => $def['timezone'],
                    'dial_code' => $def['dial_code'],
                    'sort_order' => $def['sort_order'] ?? 0,
                    'is_active' => Market::where('key', $key)->value('is_active') ?? ($def['is_active'] ?? true),
                    'is_default' => Market::where('key', $key)->value('is_default') ?? ($def['is_default'] ?? false),
                ],
            );

            // Sync legal statuses for this market
            foreach ($def['legal_statuses'] ?? [] as $ls) {
                LegalStatus::updateOrCreate(
                    ['market_key' => $key, 'key' => $ls['key']],
                    [
                        'name' => $ls['name'],
                        'description' => $ls['description'] ?? null,
                        'vat_rate' => $ls['vat_rate'] ?? 0,
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
     * Clear the in-memory cache.
     */
    public static function clearCache(): void
    {
        static::$cache = null;
    }
}
