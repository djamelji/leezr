<?php

namespace App\Core\Typography;

use App\Platform\Models\PlatformFontFamily;
use App\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves typography configuration for a given scope.
 *
 * Returns the raw payload enriched with resolved font_faces (public URLs)
 * for local fonts, or Google Fonts metadata for Google source.
 * Global strict mode: company uses platform settings (no override).
 */
class TypographyResolverService
{
    public static function forPlatform(): array
    {
        $db = PlatformSetting::instance()->typography ?? [];
        $defaults = TypographyPayload::defaults();

        $payload = new TypographyPayload(
            activeSource: $db['active_source'] ?? $defaults->activeSource,
            activeFamilyId: $db['active_family_id'] ?? $defaults->activeFamilyId,
            googleFontsEnabled: $db['google_fonts_enabled'] ?? $defaults->googleFontsEnabled,
            googleActiveFamily: $db['google_active_family'] ?? $defaults->googleActiveFamily,
            googleWeights: $db['google_weights'] ?? $defaults->googleWeights,
            headingsFamilyId: $db['headings_family_id'] ?? $defaults->headingsFamilyId,
            bodyFamilyId: $db['body_family_id'] ?? $defaults->bodyFamilyId,
        );

        // Guard: if Google Fonts disabled, force source to local
        $activeSource = $payload->activeSource;
        if (! $payload->googleFontsEnabled && $activeSource === 'google') {
            $activeSource = 'local';
        }

        $result = $payload->toArray();
        $result['active_source'] = $activeSource;
        $result['active_family_name'] = null;
        $result['font_faces'] = [];

        // Resolve local font faces
        if ($activeSource === 'local' && $payload->activeFamilyId) {
            $family = PlatformFontFamily::with('fonts')
                ->where('id', $payload->activeFamilyId)
                ->where('is_enabled', true)
                ->first();

            if ($family) {
                $result['active_family_name'] = $family->name;
                $result['font_faces'] = $family->fonts->map(fn ($font) => [
                    'weight' => $font->weight,
                    'style' => $font->style,
                    'url' => Storage::disk('public')->url($font->file_path),
                    'format' => $font->format,
                ])->toArray();
            }
        }

        // Resolve Google font name
        if ($activeSource === 'google' && $payload->googleActiveFamily) {
            $result['active_family_name'] = $payload->googleActiveFamily;
        }

        return $result;
    }

    public static function forCompany(): array
    {
        return static::forPlatform();
    }
}
