<?php

namespace App\Console\Commands;

use App\Core\Alerts\PlatformAlert;
use App\Core\Registration\RegistrationFunnelEvent;
use Illuminate\Console\Command;

class DetectAbandonedRegistrationsCommand extends Command
{
    protected $signature = 'registration:detect-abandoned';

    protected $description = 'Detect registration sessions abandoned in the last 24h and create alerts';

    public function handle(): int
    {
        $completedSessions = RegistrationFunnelEvent::step('completed')
            ->where('created_at', '>=', now()->subDay())
            ->pluck('session_id');

        $abandoned = RegistrationFunnelEvent::step('started')
            ->where('created_at', '>=', now()->subDay())
            ->where('created_at', '<=', now()->subHours(2)) // give 2h grace period
            ->whereNotIn('session_id', $completedSessions)
            ->count();

        if ($abandoned > 5) {
            $severity = $abandoned > 15 ? 'critical' : 'warning';
            $fingerprint = PlatformAlert::fingerprint('registration', 'abandonment_high', null, null);

            // Only create if no active alert with the same fingerprint
            $exists = PlatformAlert::where('fingerprint', $fingerprint)
                ->where('status', 'active')
                ->exists();

            if (! $exists) {
                PlatformAlert::create([
                    'source' => 'registration',
                    'type' => 'abandonment_high',
                    'severity' => $severity,
                    'status' => 'active',
                    'title' => "High registration abandonment: {$abandoned} sessions abandoned in last 24h",
                    'description' => "Detected {$abandoned} abandoned registration sessions in the last 24 hours (2h grace period).",
                    'fingerprint' => $fingerprint,
                    'metadata' => ['abandoned_count' => $abandoned],
                ]);
            }
        }

        $this->info("Detected {$abandoned} abandoned registrations.");

        return self::SUCCESS;
    }
}
