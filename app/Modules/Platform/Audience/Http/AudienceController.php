<?php

namespace App\Modules\Platform\Audience\Http;

use App\Core\Settings\MaintenanceSettingsPayload;
use App\Http\Controllers\Controller;
use App\Platform\Models\PlatformSetting;
use App\Modules\Platform\Audience\AudienceConfirmService;
use App\Modules\Platform\Audience\AudienceSubscribeService;
use App\Modules\Platform\Audience\AudienceUnsubscribeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AudienceController extends Controller
{
    public function subscribe(Request $request): JsonResponse|Response
    {
        // Honeypot — bots fill hidden fields
        if ($request->filled('hp_field')) {
            return response()->noContent();
        }

        $validated = $request->validate([
            'list_slug' => 'required|string|max:64',
            'email' => 'required|email|max:255',
        ]);

        AudienceSubscribeService::handle(
            listSlug: $validated['list_slug'],
            email: $validated['email'],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'message' => "You're on the list. Confirmation emails will be enabled soon.",
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $result = AudienceConfirmService::handle($validated['token']);

        return response()->json($result);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $result = AudienceUnsubscribeService::handle($validated['token']);

        return response()->json($result);
    }

    public function maintenancePage(): JsonResponse
    {
        $p = MaintenanceSettingsPayload::fromSettings();
        $settings = PlatformSetting::instance();

        return response()->json([
            'app_name' => $settings->general['app_name'] ?? 'Leezr',
            'enabled' => $p->enabled,
            'headline' => $p->headline,
            'subheadline' => $p->subheadline,
            'description' => $p->description,
            'cta_text' => $p->ctaText,
            'list_slug' => $p->listSlug,
        ]);
    }
}
