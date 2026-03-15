<?php

namespace App\Http\Middleware;

use App\Core\Auth\TwoFactorService;
use App\Platform\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatform2FA
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('platform');

        if (!$user) {
            return $next($request);
        }

        // Check if 2FA enforcement is enabled in platform settings
        $settings = PlatformSetting::instance();
        if (!($settings->general['require_2fa'] ?? false)) {
            return $next($request);
        }

        if ($this->twoFactor->isEnabled($user)) {
            return $next($request);
        }

        // 2FA not set up — block access, return 403 with redirect hint
        return response()->json([
            'message' => 'Two-factor authentication is required.',
            'two_factor_required' => true,
            'redirect' => '/platform/account/security',
        ], 403);
    }
}
