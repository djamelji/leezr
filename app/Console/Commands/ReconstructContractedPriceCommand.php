<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use Illuminate\Console\Command;

/**
 * ADR-589 — Reconstruct the TRUE contracted plan price from billing history.
 *
 * The ADR-587 backfill locked existing subscriptions at the CURRENT catalog price, which is not
 * necessarily the price originally subscribed. The reliable historical source is the earliest
 * FINALIZED invoice's `plan` line (the amount actually billed). This command:
 *   - reconstructs plan_price_cents from that line when a billing history exists;
 *   - flags as AMBIGUOUS (never invents a price) subscriptions with no invoice history;
 *   - is dry-run by default; --apply writes and audits each correction.
 *
 * Idempotent, read-only unless --apply. Never run automatically.
 */
class ReconstructContractedPriceCommand extends Command
{
    protected $signature = 'billing:reconstruct-contracted-price {--apply : Write corrections (default is dry-run)}';

    protected $description = 'Reconstruct contracted plan price from the first issued invoice (dry-run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $stats = ['reconstructed' => 0, 'already_correct' => 0, 'ambiguous_no_history' => 0, 'corrected' => 0];
        $ambiguous = [];

        $subs = Subscription::whereIn('status', ['active', 'trialing', 'past_due'])->where('is_current', 1)->orderBy('id')->get();

        foreach ($subs as $sub) {
            $firstInvoice = Invoice::withoutGlobalScopes()
                ->where('subscription_id', $sub->id)->whereNotNull('finalized_at')
                ->orderBy('finalized_at')->orderBy('id')->first();

            $planLine = $firstInvoice?->lines()->where('type', 'plan')->orderBy('id')->first();
            $historical = $planLine ? (int) $planLine->unit_amount : null;

            if ($historical === null) {
                // No billing history → do NOT invent a price. Flag; the creation snapshot (catalog at
                // subscribe time) stands, but migration of these should be blocked until reviewed.
                $stats['ambiguous_no_history']++;
                $ambiguous[] = $sub->id;

                continue;
            }

            if ((int) ($sub->plan_price_cents ?? -1) === $historical) {
                $stats['already_correct']++;

                continue;
            }

            $stats['reconstructed']++;
            $this->line(sprintf('  sub #%d (company %d): snapshot=%s → historical(invoice #%d)=%d', $sub->id, $sub->company_id, var_export($sub->plan_price_cents, true), $firstInvoice->id, $historical));

            if ($apply) {
                $before = $sub->plan_price_cents;
                $sub->plan_price_cents = $historical;
                $sub->save();
                $stats['corrected']++;
                app(AuditLogger::class)->logPlatform('subscription.contracted_price_reconstructed', 'subscription', (string) $sub->id, [
                    'severity' => 'warning',
                    'metadata' => ['company_id' => $sub->company_id, 'from_cents' => $before, 'to_cents' => $historical, 'source_invoice_id' => $firstInvoice->id],
                ]);
            }
        }

        $mode = $apply ? 'APPLIED' : 'DRY-RUN';
        $this->info("[{$mode}] reconstructed={$stats['reconstructed']} corrected={$stats['corrected']} already_correct={$stats['already_correct']} ambiguous_no_history={$stats['ambiguous_no_history']}");
        if (! empty($ambiguous)) {
            $this->warn('Ambiguous (no invoice history — migration should be blocked until reviewed): '.implode(',', $ambiguous));
        }

        return self::SUCCESS;
    }
}
