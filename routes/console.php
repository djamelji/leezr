<?php

use App\Core\Markets\Jobs\FxRateFetchJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FX rate refresh every 6 hours (ADR-104)
Schedule::job(new FxRateFetchJob)->cron('0 */6 * * *')->withoutOverlapping();

// Subscription auto-renewal — daily (ADR-223)
Schedule::command('billing:renew')->daily()->withoutOverlapping();

// Dunning retry — daily (ADR-136)
Schedule::command('billing:process-dunning')->daily()->withoutOverlapping();

// Reconciliation — weekly (ADR-140)
Schedule::command('billing:reconcile')->weekly()->withoutOverlapping();

// Webhook recovery — every 10 minutes (ADR-228)
Schedule::command('billing:recover-webhooks')->everyTenMinutes()->withoutOverlapping();

// Checkout recovery — every 10 minutes (ADR-229)
Schedule::command('billing:recover-checkouts')->everyTenMinutes()->withoutOverlapping();
