<?php

namespace App\Modules\Platform\Markets\Http;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Markets\ReadModels\MarketDetailReadModel;
use App\Core\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketCrudController
{
    public function index(): JsonResponse
    {
        $markets = Market::withCount(['companies', 'legalStatuses'])
            ->with('languages:key,name,native_name')
            ->orderBy('sort_order')
            ->get();

        return response()->json($markets);
    }

    public function show(string $key, Request $request): JsonResponse
    {
        $market = Market::where('key', $key)
            ->with(['legalStatuses' => fn ($q) => $q->orderBy('sort_order'), 'languages:key,name,native_name'])
            ->firstOrFail();

        $companiesPage = (int) $request->query('companies_page', 1);

        return response()->json([
            'market' => array_merge($market->toArray(), [
                'companies_count' => Company::where('market_key', $key)->count(),
                'legal_statuses_count' => $market->legalStatuses->count(),
            ]),
            'companies' => MarketDetailReadModel::companiesForMarket($key, 15),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', 'unique:markets,key', 'regex:/^[A-Z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:50'],
            'dial_code' => ['required', 'string', 'max:10'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        // If setting as default, unset previous default
        if (!empty($validated['is_default'])) {
            Market::where('is_default', true)->update(['is_default' => false]);
        }

        $market = Market::create($validated);

        MarketRegistry::clearCache();

        return response()->json([
            'message' => 'Market created.',
            'market' => $market->load(['legalStatuses', 'languages']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $market = Market::findOrFail($id);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', "unique:markets,key,{$market->id}", 'regex:/^[A-Z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:50'],
            'dial_code' => ['required', 'string', 'max:10'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'language_keys' => ['nullable', 'array'],
            'language_keys.*' => ['string', 'exists:languages,key'],
        ]);

        $languageKeys = $validated['language_keys'] ?? null;
        unset($validated['language_keys']);

        $market->update($validated);

        if ($languageKeys !== null) {
            $market->languages()->sync(
                array_combine($languageKeys, array_fill(0, count($languageKeys), []))
            );
        }

        MarketRegistry::clearCache();

        return response()->json([
            'message' => 'Market updated.',
            'market' => $market->load(['legalStatuses', 'languages']),
        ]);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $market = Market::findOrFail($id);

        if ($market->is_active) {
            $count = Company::where('market_key', $market->key)->count();

            if ($count > 0) {
                return response()->json([
                    'message' => "Cannot deactivate: {$count} companies are using this market.",
                    'companies_count' => $count,
                ], 422);
            }
        }

        $market->update(['is_active' => !$market->is_active]);

        MarketRegistry::clearCache();

        return response()->json([
            'message' => $market->is_active ? 'Market activated.' : 'Market deactivated.',
            'market' => $market->load(['legalStatuses', 'languages']),
        ]);
    }

    public function setDefault(int $id): JsonResponse
    {
        $market = Market::findOrFail($id);

        if (!$market->is_active) {
            return response()->json([
                'message' => 'Cannot set inactive market as default.',
            ], 422);
        }

        // Unset previous default
        Market::where('is_default', true)->update(['is_default' => false]);

        $market->update(['is_default' => true]);

        MarketRegistry::clearCache();

        return response()->json([
            'message' => 'Default market updated.',
            'market' => $market->load(['legalStatuses', 'languages']),
        ]);
    }
}
