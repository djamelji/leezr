<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Markets\TranslationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicI18nController
{
    public function bundle(Request $request, string $locale, ?string $namespace = null): JsonResponse
    {
        $marketKey = $request->query('market');

        $translations = TranslationRepository::bundle($locale, $namespace, $marketKey);

        return response()->json($translations);
    }
}
