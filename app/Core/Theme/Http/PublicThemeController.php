<?php

namespace App\Core\Theme\Http;

use App\Core\Theme\UIResolverService;
use App\Core\Typography\TypographyResolverService;
use App\Http\Controllers\Controller;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

class PublicThemeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $theme = UIResolverService::forPlatform();
        $typo = TypographyResolverService::forPlatform();
        $settings = PlatformSetting::instance();

        return response()->json([
            'app_name' => $settings->general['app_name'] ?? 'Leezr',
            'primary_color' => $theme->primaryColor,
            'primary_darken_color' => $theme->primaryDarkenColor,
            'typography' => [
                'active_source' => $typo['active_source'],
                'active_family_name' => $typo['active_family_name'],
                'font_faces' => $typo['font_faces'],
                'google_weights' => $typo['google_weights'] ?? [],
            ],
        ]);
    }
}
