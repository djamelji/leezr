<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Theme\UIResolverService;
use App\Core\Typography\TypographyResolverService;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicThemeController
{
    /**
     * ADR-862 — read on every full page load by every visitor (600/min per IP since ADR-861) : the platform-wide,
     * user-independent payload is cached (7 queries → 0). Forgotten on any change of its sources (AppServiceProvider :
     * PlatformSetting, PlatformFontFamily, PlatformFont) ; the TTL only bounds a change made outside Eloquent.
     */
    public const CACHE_KEY = 'public:theme:v1';

    public function __invoke(): JsonResponse
    {
        return response()->json(Cache::remember(self::CACHE_KEY, 300, fn () => $this->payload()));
    }

    private function payload(): array
    {
        $theme = UIResolverService::forPlatform();
        $typo = TypographyResolverService::forPlatform();
        $settings = PlatformSetting::instance();

        return [
            'app_name' => $settings->general['app_name'] ?? 'Leezr',
            'primary_color' => $theme->primaryColor,
            'primary_darken_color' => $theme->primaryDarkenColor,
            'typography' => [
                'active_source' => $typo['active_source'],
                'active_family_name' => $typo['active_family_name'],
                'font_faces' => $typo['font_faces'],
                'google_weights' => $typo['google_weights'] ?? [],
            ],
        ];
    }
}
