<?php

namespace App\Console\Commands;

use App\Modules\Workforce\Onboarding\OnboardingLifecycleService;
use Illuminate\Console\Command;

/** ADR-796.1 — records the expiry of onboarding links past their date (hourly, idempotent, audited). */
class OnboardingExpireCommand extends Command
{
    protected $signature = 'onboarding:expire';

    protected $description = 'Record the expiry of onboarding links past their date and drop expired sessions';

    public function handle(OnboardingLifecycleService $lifecycle): int
    {
        $this->line('Expired: '.$lifecycle->expireDue().' · expired sessions removed: '.$lifecycle->purgeExpiredSessions());

        return self::SUCCESS;
    }
}
