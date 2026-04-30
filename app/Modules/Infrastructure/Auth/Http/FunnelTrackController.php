<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Registration\RegistrationFunnelEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class FunnelTrackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'step' => 'required|string|in:started,company_info,admin_user,plan_selected,payment_info',
            'metadata' => 'nullable|array',
        ]);

        RegistrationFunnelEvent::create([
            'session_id' => session()->getId(),
            'company_id' => null,
            'step' => $request->step,
            'metadata' => $request->metadata,
        ]);

        return response()->json(['ok' => true]);
    }
}
