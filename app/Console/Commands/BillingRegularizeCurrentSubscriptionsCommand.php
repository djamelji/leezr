<?php

namespace App\Console\Commands;

use App\Core\Billing\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Regularize subscriptions left in an inconsistent state: a status that SHOULD be the
 * current contract (active/trialing/past_due/pending_payment) but with is_current=NULL
 * (seeder bug, audit 13 / ANO-001). Such rows are skipped by billing:renew (which
 * filters is_current=1) → their period freezes → "next invoice" shows a past date.
 *
 * SAFE by design: dry-run by default; sets is_current=1 only; triggers NO renewal,
 * invoice, charge or financial operation. Respects the unique (company_id, is_current)
 * constraint — if a company already has a current subscription, the orphan is reported,
 * never flipped (that would be a conflict needing manual decision). Idempotent.
 */
class BillingRegularizeCurrentSubscriptionsCommand extends Command
{
    protected $signature = 'billing:regularize-current-subscriptions {--dry-run}';

    protected $description = 'Set is_current=1 on active-like subscriptions wrongly left is_current=NULL (no billing side effects)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Subscription::whereIn('status', Subscription::CURRENT_ALLOWED_STATUSES)
            ->whereNull('is_current')
            ->get();

        $fixed = 0;
        $conflicts = 0;

        foreach ($candidates as $sub) {
            $hasCurrent = Subscription::where('company_id', $sub->company_id)
                ->where('is_current', 1)
                ->exists();

            if ($hasCurrent) {
                $conflicts++;
                $this->warn("  Conflict: company #{$sub->company_id} already has a current subscription — sub #{$sub->id} ({$sub->status}) left as-is (manual review).");

                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would set is_current=1: sub #{$sub->id} company #{$sub->company_id} status={$sub->status}");

                continue;
            }

            $sub->update(['is_current' => 1]);
            $fixed++;
            Log::channel('billing')->info('Regularized is_current', [
                'subscription_id' => $sub->id,
                'company_id' => $sub->company_id,
                'status' => $sub->status,
            ]);
        }

        $this->info($dryRun
            ? "Dry-run: {$candidates->count()} candidate(s), {$conflicts} conflict(s). No change."
            : "Regularized {$fixed} subscription(s), {$conflicts} conflict(s) skipped.");

        return self::SUCCESS;
    }
}
