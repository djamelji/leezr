<?php

namespace App\Modules\Platform\Markets\Http;

use App\Core\Markets\MarketRegistry;
use App\Modules\Platform\Markets\ReadModels\PlatformMarketReadModel;
use App\Modules\Platform\Markets\UseCases\SetDefaultMarketUseCase;
use App\Modules\Platform\Markets\UseCases\ToggleMarketActiveUseCase;
use App\Modules\Platform\Markets\UseCases\UpsertMarketData;
use App\Modules\Platform\Markets\UseCases\UpsertMarketUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketCrudController
{
    public function index(): JsonResponse
    {
        return response()->json(PlatformMarketReadModel::catalog());
    }

    public function show(string $key): JsonResponse
    {
        return response()->json(PlatformMarketReadModel::detail($key));
    }

    public function store(Request $request, UpsertMarketUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', 'unique:markets,key', 'regex:/^[A-Z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:50'],
            'dial_code' => ['required', 'string', 'max:10'],
            'flag_code' => ['nullable', 'string', 'size:2'],
            'flag_svg' => ['nullable', 'string', 'max:50000'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $market = $useCase->execute(UpsertMarketData::fromValidated(null, $validated));

        return response()->json([
            'message' => 'Market created.',
            'market' => $market,
        ], 201);
    }

    public function update(Request $request, int $id, UpsertMarketUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', "unique:markets,key,{$id}", 'regex:/^[A-Z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:50'],
            'dial_code' => ['required', 'string', 'max:10'],
            'flag_code' => ['nullable', 'string', 'size:2'],
            'flag_svg' => ['nullable', 'string', 'max:50000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'language_keys' => ['nullable', 'array'],
            'language_keys.*' => ['string', 'exists:languages,key'],
        ]);

        $market = $useCase->execute(UpsertMarketData::fromValidated($id, $validated));

        return response()->json([
            'message' => 'Market updated.',
            'market' => $market,
        ]);
    }

    public function toggleActive(int $id, ToggleMarketActiveUseCase $useCase): JsonResponse
    {
        $market = $useCase->execute($id);

        return response()->json([
            'message' => $market->is_active ? 'Market activated.' : 'Market deactivated.',
            'market' => $market,
        ]);
    }

    public function setDefault(int $id, SetDefaultMarketUseCase $useCase): JsonResponse
    {
        $market = $useCase->execute($id);

        return response()->json([
            'message' => 'Default market updated.',
            'market' => $market,
        ]);
    }

    public function export(): JsonResponse
    {
        return response()->json(PlatformMarketReadModel::export());
    }

    public function importPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:json', 'max:5120'],
        ]);

        $content = file_get_contents($validated['file']->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return response()->json(['message' => 'Invalid JSON file.'], 422);
        }

        $data = array_filter($data, fn ($key) => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

        return response()->json(PlatformMarketReadModel::importPreview($data));
    }

    public function importApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:json', 'max:5120'],
        ]);

        $content = file_get_contents($validated['file']->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return response()->json(['message' => 'Invalid JSON file.'], 422);
        }

        $result = MarketRegistry::importFromArray($data);

        return response()->json([
            'message' => "Import applied: {$result['created']} created, {$result['updated']} updated.",
            ...$result,
        ]);
    }
}
