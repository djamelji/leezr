<?php

namespace App\Modules\Platform\Markets;

use App\Core\Markets\Language;
use App\Core\Markets\LegalStatus;

/**
 * Thin service for trivial CRUD operations in the markets module.
 * No business invariants — just wraps Eloquent out of controllers.
 */
class MarketModuleCrudService
{
    // ─── Language (trivial CRUD) ─────────────────────────

    public static function createLanguage(array $validated): Language
    {
        // Trivial default management (1 invariant, no audit/cache)
        if (!empty($validated['is_default'])) {
            Language::where('is_default', true)->update(['is_default' => false]);
        }

        return Language::create($validated);
    }

    public static function updateLanguage(int $id, array $validated): Language
    {
        $language = Language::findOrFail($id);
        $language->update($validated);

        return $language;
    }

    public static function toggleLanguageActive(int $id): Language
    {
        $language = Language::findOrFail($id);
        $language->update(['is_active' => !$language->is_active]);

        return $language;
    }

    // ─── LegalStatus (trivial CRUD) ─────────────────────

    public static function deleteLegalStatus(int $id): void
    {
        LegalStatus::findOrFail($id)->delete();
    }

    public static function reorderLegalStatuses(string $marketKey, array $ids): void
    {
        foreach ($ids as $index => $id) {
            LegalStatus::where('id', $id)
                ->where('market_key', $marketKey)
                ->update(['sort_order' => $index]);
        }
    }
}
