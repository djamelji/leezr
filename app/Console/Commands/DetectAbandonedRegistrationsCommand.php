<?php

namespace App\Console\Commands;

use App\Core\Alerts\PlatformAlert;
use Illuminate\Console\Command;

class DetectAbandonedRegistrationsCommand extends Command
{
    protected $signature = 'registration:detect-abandoned';

    protected $description = 'Detect registration journeys that became abandoned in the last 24h (inactivity window, ADR-849) and create alerts';

    public function handle(): int
    {
        // ADR-849 : SAME definition as the dashboard (OnboardingFunnelService) — not completed and inactive for the
        // configured window ; counted here when the journey BECAME abandoned during the last 24 hours.
        $hours = (int) config('registration_funnel.abandon_after_hours');
        $now = now();
        $abandoned = app(\App\Modules\Platform\Dashboard\OnboardingFunnelService::class)
            ->journeys($now->copy()->subHours($hours + 24 + 24 * 30), $now) // journeys started up to a month before
            ->filter(fn ($j) => ! $j['completed'] && $j['last_event_at']->between($now->copy()->subHours($hours + 24), $now->copy()->subHours($hours)))
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
                    'title' => "High registration abandonment: {$abandoned} journeys abandoned in last 24h",
                    'description' => "Detected {$abandoned} registration journeys that became abandoned in the last 24 hours (no activity for {$hours}h, not completed).",
                    'fingerprint' => $fingerprint,
                    'metadata' => ['abandoned_count' => $abandoned],
                ]);
            }
        }

        $this->info("Detected {$abandoned} abandoned registrations.");

        return self::SUCCESS;
    }
}
