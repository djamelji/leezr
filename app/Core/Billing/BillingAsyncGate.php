<?php

namespace App\Core\Billing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-828 — refuses the `--async` mode of the billing commands while no worker consumes their queues :
 * nothing is dispatched, the refusal is visible (console, billing log) and nothing is recorded — the job heartbeat keeps the last real run. Synchronous runs and
 * every billing rule are unchanged.
 */
final class BillingAsyncGate
{
    public static function refuse(Command $command, string $jobKey, string $queue): bool
    {
        if (config('billing.async_queues_enabled')) {
            return false;
        }
        $message = "--async refused : no worker consumes the `{$queue}` queue (BILLING_ASYNC_QUEUES_ENABLED is off). Nothing was dispatched ; run without --async.";
        $command->error($message);
        Log::channel('billing')->warning('[billing] async mode refused', ['job' => $jobKey, 'queue' => $queue]);

        return true;
    }
}
