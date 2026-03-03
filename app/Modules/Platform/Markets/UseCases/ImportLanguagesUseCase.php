<?php

namespace App\Modules\Platform\Markets\UseCases;

use App\Core\Markets\Language;
use Illuminate\Support\Facades\DB;

class ImportLanguagesUseCase
{
    /**
     * @return array{created: int, updated: int}
     */
    public function execute(array $data): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($data, &$created, &$updated) {
            foreach ($data as $lang) {
                if (empty($lang['key']) || empty($lang['name'])) {
                    continue;
                }

                $exists = Language::where('key', $lang['key'])->exists();

                Language::updateOrCreate(
                    ['key' => $lang['key']],
                    [
                        'name' => $lang['name'],
                        'native_name' => $lang['native_name'] ?? $lang['name'],
                        'sort_order' => $lang['sort_order'] ?? 0,
                        'is_active' => $lang['is_active'] ?? true,
                        'is_default' => $lang['is_default'] ?? false,
                    ],
                );

                $exists ? $updated++ : $created++;
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }
}
