<?php

namespace App\Modules\Platform\Markets\ReadModels;

use App\Core\Markets\Language;
use App\Core\Markets\Market;
use App\Core\Markets\ReadModels\MarketDetailReadModel;
use App\Core\Models\Company;

class PlatformMarketReadModel
{
    /**
     * Catalog listing: all markets with counts + languages.
     */
    public static function catalog(): array
    {
        return Market::withCount(['companies', 'legalStatuses'])
            ->with('languages:key,name,native_name')
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }

    /**
     * Full detail for a single market.
     */
    public static function detail(string $key): array
    {
        $market = Market::where('key', $key)
            ->with(['legalStatuses' => fn ($q) => $q->orderBy('sort_order'), 'languages:key,name,native_name'])
            ->firstOrFail();

        return [
            'market' => array_merge($market->toArray(), [
                'companies_count' => Company::where('market_key', $key)->count(),
                'legal_statuses_count' => $market->legalStatuses->count(),
            ]),
            'companies' => MarketDetailReadModel::companiesForMarket($key, 15),
        ];
    }

    /**
     * Export all markets with relations for JSON export.
     */
    public static function export(): array
    {
        $markets = Market::with(['legalStatuses' => fn ($q) => $q->orderBy('sort_order'), 'languages:key'])
            ->orderBy('sort_order')
            ->get();

        $result = [
            '_meta' => [
                'version' => '1.0',
                'exported_at' => now()->toIso8601String(),
            ],
        ];

        foreach ($markets as $market) {
            $result[$market->key] = [
                'name' => $market->name,
                'currency' => $market->currency,
                'locale' => $market->locale,
                'timezone' => $market->timezone,
                'dial_code' => $market->dial_code,
                'flag_code' => $market->flag_code,
                'flag_svg' => $market->flag_svg,
                'is_active' => $market->is_active,
                'is_default' => $market->is_default,
                'sort_order' => $market->sort_order,
                'languages' => $market->languages->pluck('key')->toArray(),
                'legal_statuses' => $market->legalStatuses->map(fn ($ls) => [
                    'key' => $ls->key,
                    'name' => $ls->name,
                    'description' => $ls->description,
                    'is_vat_applicable' => $ls->is_vat_applicable,
                    'vat_rate' => $ls->vat_rate,
                    'is_default' => $ls->is_default,
                    'sort_order' => $ls->sort_order,
                ])->toArray(),
            ];
        }

        return $result;
    }

    /**
     * Language catalog with market counts.
     */
    public static function languageCatalog(): array
    {
        return Language::withCount('markets')
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }

    /**
     * Language export (simple list).
     */
    public static function languageExport(): array
    {
        return Language::orderBy('sort_order')->get()->toArray();
    }

    /**
     * Preview import: compute diff between file data and existing markets.
     */
    public static function importPreview(array $data): array
    {
        $existingKeys = Market::pluck('key')->toArray();

        $toCreate = [];
        $toUpdate = [];

        foreach ($data as $key => $def) {
            in_array($key, $existingKeys) ? $toUpdate[] = $key : $toCreate[] = $key;
        }

        return [
            'markets_to_create' => $toCreate,
            'markets_to_update' => $toUpdate,
            'total' => count($data),
        ];
    }
}
