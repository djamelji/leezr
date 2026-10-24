<?php

use App\Core\Automation\SchedulerInstrumentation as SI;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduler heartbeat (infra, every minute) ────────────
// Proves the cron (schedule:run) is firing, independent of business-task history.
// NOT a cockpit business task: excluded from ScheduledTaskRegistry by convention
// (see ScheduledTaskRegistryConsistencyTest::REGISTRY_EXCLUSIONS). No instrumentation
// (no ScheduledTaskRun bloat), always-on (no enable/disable).
Schedule::command('scheduler:heartbeat')->everyMinute()->withoutOverlapping();

// ── FX ───────────────────────────────────────────────────
// FX rate refresh every 6 hours (ADR-104)
Schedule::command('fx:rates-sync')->cron('0 */6 * * *')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/fx-rates-sync.log'))
    ->when(SI::enabled('fx:rates-sync'))
    ->before(SI::before('fx:rates-sync'))
    ->onSuccess(SI::onSuccess('fx:rates-sync'))
    ->onFailure(SI::onFailure('fx:rates-sync'));

// ── Billing ──────────────────────────────────────────────
// Subscription auto-renewal — daily (ADR-223)
Schedule::command('billing:renew')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-renew.log'))
    ->when(SI::enabled('billing:renew'))
    ->before(SI::before('billing:renew'))
    ->onSuccess(SI::onSuccess('billing:renew'))
    ->onFailure(SI::onFailure('billing:renew'));

// Dunning retry — daily (ADR-136)
Schedule::command('billing:process-dunning')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-process-dunning.log'))
    ->when(SI::enabled('billing:process-dunning'))
    ->before(SI::before('billing:process-dunning'))
    ->onSuccess(SI::onSuccess('billing:process-dunning'))
    ->onFailure(SI::onFailure('billing:process-dunning'));

// Reconciliation — weekly (ADR-140)
Schedule::command('billing:reconcile')->weekly()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-reconcile.log'))
    ->when(SI::enabled('billing:reconcile'))
    ->before(SI::before('billing:reconcile'))
    ->onSuccess(SI::onSuccess('billing:reconcile'))
    ->onFailure(SI::onFailure('billing:reconcile'));

// Webhook recovery — every 10 minutes (ADR-228)
Schedule::command('billing:recover-webhooks')->everyTenMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-recover-webhooks.log'))
    ->when(SI::enabled('billing:recover-webhooks'))
    ->before(SI::before('billing:recover-webhooks'))
    ->onSuccess(SI::onSuccess('billing:recover-webhooks'))
    ->onFailure(SI::onFailure('billing:recover-webhooks'));

// Checkout recovery — every 10 minutes (ADR-229)
Schedule::command('billing:recover-checkouts')->everyTenMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-recover-checkouts.log'))
    ->when(SI::enabled('billing:recover-checkouts'))
    ->before(SI::before('billing:recover-checkouts'))
    ->onSuccess(SI::onSuccess('billing:recover-checkouts'))
    ->onFailure(SI::onFailure('billing:recover-checkouts'));

// Dead letter queue monitoring — hourly (ADR-266)
Schedule::command('billing:check-dlq')->hourly()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-check-dlq.log'))
    ->when(SI::enabled('billing:check-dlq'))
    ->before(SI::before('billing:check-dlq'))
    ->onSuccess(SI::onSuccess('billing:check-dlq'))
    ->onFailure(SI::onFailure('billing:check-dlq'));

// Card expiry notifications — daily (ADR-272)
Schedule::command('billing:check-expiring-cards')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-check-expiring-cards.log'))
    ->when(SI::enabled('billing:check-expiring-cards'))
    ->before(SI::before('billing:check-expiring-cards'))
    ->onSuccess(SI::onSuccess('billing:check-expiring-cards'))
    ->onFailure(SI::onFailure('billing:check-expiring-cards'));

// Trial expiry notifications — daily (ADR-272)
Schedule::command('billing:check-trial-expiring')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-check-trial-expiring.log'))
    ->when(SI::enabled('billing:check-trial-expiring'))
    ->before(SI::before('billing:check-trial-expiring'))
    ->onSuccess(SI::onSuccess('billing:check-trial-expiring'))
    ->onFailure(SI::onFailure('billing:check-trial-expiring'));

// Trial auto-expiration — daily (ADR-360)
Schedule::command('billing:expire-trials')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-expire-trials.log'))
    ->when(SI::enabled('billing:expire-trials'))
    ->before(SI::before('billing:expire-trials'))
    ->onSuccess(SI::onSuccess('billing:expire-trials'))
    ->onFailure(SI::onFailure('billing:expire-trials'));

// Scheduled SEPA debits collection — daily at 06:00 (ADR-328 S2)
Schedule::command('billing:collect-scheduled')->dailyAt('06:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/billing-collect-scheduled.log'))
    ->when(SI::enabled('billing:collect-scheduled'))
    ->before(SI::before('billing:collect-scheduled'))
    ->onSuccess(SI::onSuccess('billing:collect-scheduled'))
    ->onFailure(SI::onFailure('billing:collect-scheduled'));

// ── Documents ────────────────────────────────────────────
// Document expiration check — daily (ADR-389)
Schedule::command('documents:check-expiration')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/documents-check-expiration.log'))
    ->when(SI::enabled('documents:check-expiration'))
    ->before(SI::before('documents:check-expiration'))
    ->onSuccess(SI::onSuccess('documents:check-expiration'))
    ->onFailure(SI::onFailure('documents:check-expiration'));

// Document auto-renew — daily at 08:00 (ADR-397)
Schedule::command('documents:auto-renew')->dailyAt('08:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/documents-auto-renew.log'))
    ->when(SI::enabled('documents:auto-renew'))
    ->before(SI::before('documents:auto-renew'))
    ->onSuccess(SI::onSuccess('documents:auto-renew'))
    ->onFailure(SI::onFailure('documents:auto-renew'));

// Document auto-remind — daily at 09:00 (ADR-397)
Schedule::command('documents:auto-remind')->dailyAt('09:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/documents-auto-remind.log'))
    ->when(SI::enabled('documents:auto-remind'))
    ->before(SI::before('documents:auto-remind'))
    ->onSuccess(SI::onSuccess('documents:auto-remind'))
    ->onFailure(SI::onFailure('documents:auto-remind'));

// ── Workflow Engine (ADR-437) ────────────────────────────
// Reset daily execution counters at midnight
Schedule::command('workflow:reset-daily-counters')->daily()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/workflow-reset-daily-counters.log'))
    ->when(SI::enabled('workflow:reset-daily-counters'))
    ->before(SI::before('workflow:reset-daily-counters'))
    ->onSuccess(SI::onSuccess('workflow:reset-daily-counters'))
    ->onFailure(SI::onFailure('workflow:reset-daily-counters'));

// ── Alert Center (ADR-438) ──────────────────────────────
// Evaluate alert rules every 5 minutes
Schedule::command('alerts:evaluate')->everyFiveMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/alerts-evaluate.log'))
    ->when(SI::enabled('alerts:evaluate'))
    ->before(SI::before('alerts:evaluate'))
    ->onSuccess(SI::onSuccess('alerts:evaluate'))
    ->onFailure(SI::onFailure('alerts:evaluate'));

// Escalate unacknowledged critical alerts every 15 minutes (ADR-469)
Schedule::command('alerts:escalate')->everyFifteenMinutes()->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/alerts-escalate.log'))
    ->when(SI::enabled('alerts:escalate'))
    ->before(SI::before('alerts:escalate'))
    ->onSuccess(SI::onSuccess('alerts:escalate'))
    ->onFailure(SI::onFailure('alerts:escalate'));

// ── Usage Monitoring (P3-4) ────────────────────────────
// Collect daily usage snapshots at 23:55
Schedule::command('usage:collect-snapshots')->dailyAt('23:55')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/usage-collect-snapshots.log'))
    ->when(SI::enabled('usage:collect-snapshots'))
    ->before(SI::before('usage:collect-snapshots'))
    ->onSuccess(SI::onSuccess('usage:collect-snapshots'))
    ->onFailure(SI::onFailure('usage:collect-snapshots'));

// ── Email IMAP fetch ───────────────────────────────────
// ADR-610 (LOT 3): every minute (was every 5) to cut arrival→inbox latency. Safe because:
// real fetch ≈ <1s; withoutOverlapping(10) holds a 10-min mutex so a hung run self-heals and can
// never pile up on the next tick; IMAP/Redis unavailability degrade gracefully (command guards +
// non-blocking SSE publish); dedup is by Message-ID so re-runs never duplicate or touch history.
Schedule::command('email:fetch-inbox')->everyMinute()->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/scheduler/email-fetch.log'))
    ->when(SI::enabled('email:fetch-inbox'))
    ->before(SI::before('email:fetch-inbox'))
    ->onSuccess(SI::onSuccess('email:fetch-inbox'))
    ->onFailure(SI::onFailure('email:fetch-inbox'));

// ── Registration funnel (P3-1) ────────────────────────
// Detect abandoned registrations — daily at 08:00
Schedule::command('registration:detect-abandoned')->dailyAt('08:00')->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler/registration-detect-abandoned.log'))
    ->when(SI::enabled('registration:detect-abandoned'))
    ->before(SI::before('registration:detect-abandoned'))
    ->onSuccess(SI::onSuccess('registration:detect-abandoned'))
    ->onFailure(SI::onFailure('registration:detect-abandoned'));
