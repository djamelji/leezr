<?php

namespace App\Core\Alerts\Observers;

use App\Core\Alerts\Notifications\CriticalAlertNotification;
use App\Core\Alerts\PlatformAlert;
use App\Platform\Models\PlatformUser;

/**
 * ADR-469: Observe PlatformAlert creation — notify all platform admins
 * (super_admin role) when a critical alert is created.
 */
class PlatformAlertObserver
{
    public function created(PlatformAlert $alert): void
    {
        if ($alert->severity !== 'critical') {
            return;
        }

        $admins = PlatformUser::whereHas('roles', fn ($q) => $q->where('key', 'super_admin'))->get();

        foreach ($admins as $admin) {
            $admin->notify(new CriticalAlertNotification($alert));
        }
    }
}
