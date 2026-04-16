<?php

use App\Core\Automation\SchedulerInstrumentation as SI;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── FX ───────────────────────────────────────────────────
// FX rate refresh every 6 hours (ADR-104)
Schedule::command('fx:rates-sync')->cron('0 */6 * * *')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/fx-rates-sync.log'))
    ->before(SI::before('fx:rates-sync'))
    ->onSuccess(SI::onSuccess('fx:rates-sync'))
    ->onFailure(SI::onFailure('fx:rates-sync'));

// ── Billing ──────────────────────────────────────────────
// Subscription auto-renewal — daily (ADR-223)
Schedule::command('billing:renew')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-renew.log'))
    ->before(SI::before('billing:renew'))
    ->onSuccess(SI::onSuccess('billing:renew'))
    ->onFailure(SI::onFailure('billing:renew'));

// Dunning retry — daily (ADR-136)
Schedule::command('billing:process-dunning')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-process-dunning.log'))
    ->before(SI::before('billing:process-dunning'))
    ->onSuccess(SI::onSuccess('billing:process-dunning'))
    ->onFailure(SI::onFailure('billing:process-dunning'));

// Reconciliation — weekly (ADR-140)
Schedule::command('billing:reconcile')->weekly()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-reconcile.log'))
    ->before(SI::before('billing:reconcile'))
    ->onSuccess(SI::onSuccess('billing:reconcile'))
    ->onFailure(SI::onFailure('billing:reconcile'));

// Webhook recovery — every 10 minutes (ADR-228)
Schedule::command('billing:recover-webhooks')->everyTenMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-recover-webhooks.log'))
    ->before(SI::before('billing:recover-webhooks'))
    ->onSuccess(SI::onSuccess('billing:recover-webhooks'))
    ->onFailure(SI::onFailure('billing:recover-webhooks'));

// Checkout recovery — every 10 minutes (ADR-229)
Schedule::command('billing:recover-checkouts')->everyTenMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-recover-checkouts.log'))
    ->before(SI::before('billing:recover-checkouts'))
    ->onSuccess(SI::onSuccess('billing:recover-checkouts'))
    ->onFailure(SI::onFailure('billing:recover-checkouts'));

// Dead letter queue monitoring — hourly (ADR-266)
Schedule::command('billing:check-dlq')->hourly()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-check-dlq.log'))
    ->before(SI::before('billing:check-dlq'))
    ->onSuccess(SI::onSuccess('billing:check-dlq'))
    ->onFailure(SI::onFailure('billing:check-dlq'));

// Card expiry notifications — daily (ADR-272)
Schedule::command('billing:check-expiring-cards')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-check-expiring-cards.log'))
    ->before(SI::before('billing:check-expiring-cards'))
    ->onSuccess(SI::onSuccess('billing:check-expiring-cards'))
    ->onFailure(SI::onFailure('billing:check-expiring-cards'));

// Trial expiry notifications — daily (ADR-272)
Schedule::command('billing:check-trial-expiring')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-check-trial-expiring.log'))
    ->before(SI::before('billing:check-trial-expiring'))
    ->onSuccess(SI::onSuccess('billing:check-trial-expiring'))
    ->onFailure(SI::onFailure('billing:check-trial-expiring'));

// Trial auto-expiration — daily (ADR-360)
Schedule::command('billing:expire-trials')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-expire-trials.log'))
    ->before(SI::before('billing:expire-trials'))
    ->onSuccess(SI::onSuccess('billing:expire-trials'))
    ->onFailure(SI::onFailure('billing:expire-trials'));

// Scheduled SEPA debits collection — daily at 06:00 (ADR-328 S2)
Schedule::command('billing:collect-scheduled')->dailyAt('06:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-collect-scheduled.log'))
    ->before(SI::before('billing:collect-scheduled'))
    ->onSuccess(SI::onSuccess('billing:collect-scheduled'))
    ->onFailure(SI::onFailure('billing:collect-scheduled'));

// ── Documents ────────────────────────────────────────────
// Document expiration check — daily (ADR-389)
Schedule::command('documents:check-expiration')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/documents-check-expiration.log'))
    ->before(SI::before('documents:check-expiration'))
    ->onSuccess(SI::onSuccess('documents:check-expiration'))
    ->onFailure(SI::onFailure('documents:check-expiration'));

// Document auto-renew — daily at 08:00 (ADR-397)
Schedule::command('documents:auto-renew')->dailyAt('08:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/documents-auto-renew.log'))
    ->before(SI::before('documents:auto-renew'))
    ->onSuccess(SI::onSuccess('documents:auto-renew'))
    ->onFailure(SI::onFailure('documents:auto-renew'));

// Document auto-remind — daily at 09:00 (ADR-397)
Schedule::command('documents:auto-remind')->dailyAt('09:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/documents-auto-remind.log'))
    ->before(SI::before('documents:auto-remind'))
    ->onSuccess(SI::onSuccess('documents:auto-remind'))
    ->onFailure(SI::onFailure('documents:auto-remind'));

// ── Workflow Engine (ADR-437) ────────────────────────────
// Reset daily execution counters at midnight
Schedule::command('workflow:reset-daily-counters')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/workflow-reset-daily-counters.log'))
    ->before(SI::before('workflow:reset-daily-counters'))
    ->onSuccess(SI::onSuccess('workflow:reset-daily-counters'))
    ->onFailure(SI::onFailure('workflow:reset-daily-counters'));

// ── Alert Center (ADR-438) ──────────────────────────────
// Evaluate alert rules every 5 minutes
Schedule::command('alerts:evaluate')->everyFiveMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/alerts-evaluate.log'))
    ->before(SI::before('alerts:evaluate'))
    ->onSuccess(SI::onSuccess('alerts:evaluate'))
    ->onFailure(SI::onFailure('alerts:evaluate'));
