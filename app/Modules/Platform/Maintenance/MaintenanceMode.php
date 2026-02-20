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
        $path = $request->path();
        $isMaintenancePage = $path === 'maintenance';

        // Always exempt: platform admin, audience confirm/unsubscribe
        if (
            str_starts_with($path, 'platform')
            || str_starts_with($path, 'audience/confirm')
            || str_starts_with($path, 'audience/unsubscribe')
        ) {
            return $next($request);
        }

        // Maintenance OFF → redirect away from /maintenance, allow everything else
        if (! $payload->enabled) {
            return $isMaintenancePage ? redirect('/', 302) : $next($request);
        }

        // Maintenance ON + IP whitelisted → redirect away from /maintenance, allow everything else
        if (in_array($request->ip(), $payload->allowlistIps, true)) {
            return $isMaintenancePage ? redirect('/', 302) : $next($request);
        }

        // Maintenance ON + not whitelisted → force /maintenance, block everything else
        return $isMaintenancePage ? $next($request) : redirect('/maintenance', 302);
    }
}
