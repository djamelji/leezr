<?php

namespace App\Modules\Platform\Markets\Http;

use App\Modules\Platform\Markets\MarketModuleCrudService;
use App\Modules\Platform\Markets\ReadModels\PlatformMarketReadModel;
use App\Modules\Platform\Markets\UseCases\DeleteLanguageUseCase;
use App\Modules\Platform\Markets\UseCases\ImportLanguagesUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LanguageController
{
    public function index(): JsonResponse
    {
        return response()->json(PlatformMarketReadModel::languageCatalog());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', 'unique:languages,key', 'regex:/^[a-z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $language = MarketModuleCrudService::createLanguage($validated);

        return response()->json([
            'message' => 'Language created.',
            'language' => $language,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', "unique:languages,key,{$id}", 'regex:/^[a-z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $language = MarketModuleCrudService::updateLanguage($id, $validated);

        return response()->json([
            'message' => 'Language updated.',
            'language' => $language,
        ]);
    }

    public function destroy(int $id, DeleteLanguageUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return response()->json(['message' => 'Language deleted.']);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $language = MarketModuleCrudService::toggleLanguageActive($id);

        return response()->json([
            'message' => $language->is_active ? 'Language activated.' : 'Language deactivated.',
            'language' => $language,
        ]);
    }

    public function export(): JsonResponse
    {
        return response()->json(PlatformMarketReadModel::languageExport());
    }

    public function importApply(Request $request, ImportLanguagesUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:json', 'max:5120'],
        ]);

        $content = file_get_contents($validated['file']->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return response()->json(['message' => 'Invalid JSON file.'], 422);
        }

        $result = $useCase->execute($data);

        return response()->json([
            'message' => "Import applied: {$result['created']} created, {$result['updated']} updated.",
            ...$result,
        ]);
    }
}
