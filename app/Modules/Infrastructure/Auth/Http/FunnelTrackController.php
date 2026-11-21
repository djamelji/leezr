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
            // ADR-848 (M9) : public, unauthenticated endpoint → bounded metadata (was any array of any size)
            'metadata' => 'nullable|array|max:20',
            'metadata.*' => ['nullable', function (string $attr, mixed $v, \Closure $fail) {
                if (! is_scalar($v) || mb_strlen((string) $v) > 255) {
                    $fail("$attr must be a scalar of at most 255 characters.");
                }
            }],
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
