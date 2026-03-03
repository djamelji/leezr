<?php

namespace App\Modules\Platform\Markets\UseCases;

use App\Core\Markets\Language;
use Illuminate\Validation\ValidationException;

class DeleteLanguageUseCase
{
    public function execute(int $id): void
    {
        $language = Language::findOrFail($id);

        $marketCount = $language->markets()->count();

        if ($marketCount > 0) {
            throw ValidationException::withMessages([
                'language' => "Cannot delete: {$marketCount} markets are using this language.",
            ]);
        }

        $language->delete();
    }
}
