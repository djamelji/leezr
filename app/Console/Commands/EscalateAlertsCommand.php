<?php

namespace App\Console\Commands;

use App\Core\Alerts\Notifications\CriticalAlertNotification;
use App\Core\Alerts\PlatformAlert;
use App\Platform\Models\PlatformUser;
use Illuminate\Console\Command;

/**
 * ADR-469: Escalate unacknowledged critical alerts by re-notifying admins.
 * Runs every 15 minutes. Caps at 3 escalations per alert.
 */
class EscalateAlertsCommand extends Command
{
    protected $signature = 'alerts:escalate';

    protected $description = 'Escalate unacknowledged critical alerts by re-notifying platform admins';

    public function handle(): int
    {
        // Find critical alerts unacknowledged for more than 30 minutes
        $staleAlerts = PlatformAlert::active()
            ->critical()
            ->whereNull('acknowledged_at')
            ->where('created_at', '<=', now()->subMinutes(30))
            ->get();

        if ($staleAlerts->isEmpty()) {
            $this->info('No alerts to escalate.');

            return self::SUCCESS;
        }

        $admins = PlatformUser::whereHas('roles', fn ($q) => $q->where('key', 'super_admin'))->get();

        foreach ($staleAlerts as $alert) {
            $meta = $alert->metadata ?? [];
            $escalationCount = ($meta['escalation_count'] ?? 0) + 1;
            $meta['escalation_count'] = $escalationCount;
            $meta['last_escalated_at'] = now()->toIso8601String();
            $alert->update(['metadata' => $meta]);

            // Re-notify if under 3 escalations
            if ($escalationCount <= 3) {
                foreach ($admins as $admin) {
                    $admin->notify(new CriticalAlertNotification($alert, $escalationCount));
                }

                $this->info("Escalated alert #{$alert->id} (count: {$escalationCount})");
            } else {
                $this->info("Alert #{$alert->id} already escalated {$escalationCount} times — skipping notification.");
            }
        }

        return self::SUCCESS;
    }
}
