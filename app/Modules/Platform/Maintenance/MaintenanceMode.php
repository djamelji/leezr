<?php

namespace App\Modules\Platform\Maintenance;

use App\Core\Settings\MaintenanceSettingsPayload;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = MaintenanceSettingsPayload::fromSettings();

        if (! $payload->enabled) {
            return $next($request);
        }

        $path = $request->path();

        // Exempt routes: platform, maintenance page itself, audience confirm/unsubscribe
        if (
            str_starts_with($path, 'platform')
            || $path === 'maintenance'
            || str_starts_with($path, 'audience/confirm')
            || str_starts_with($path, 'audience/unsubscribe')
        ) {
            return $next($request);
        }

        // IP allowlist bypass
        if (in_array($request->ip(), $payload->allowlistIps, true)) {
            return $next($request);
        }

        return redirect('/maintenance', 302);
    }
}
