<?php

namespace App\Console\Commands;

use App\Core\Alerts\PlatformAlert;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Support\SupportTicket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-438: Evaluate alert rules and create/auto-resolve platform alerts.
 *
 * Runs every 5 minutes via scheduler.
 * Uses fingerprint-based deduplication to avoid duplicate alerts.
 */
class AlertEvaluatorCommand extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate alert rules and create/resolve platform alerts';

    private int $created = 0;

    private int $resolved = 0;

    public function handle(): int
    {
        $this->evaluateInvoiceOverdue7d();
        $this->evaluateInvoiceOverdue30d();
        $this->evaluatePaymentFailed3x();
        $this->evaluateTicketUnassigned24h();
        $this->evaluateSubscriptionsPending();

        $this->autoResolveStaleAlerts();

        $this->info("Alert evaluation complete: {$this->created} created, {$this->resolved} resolved.");

        return self::SUCCESS;
    }

    // ── Rule: invoice_overdue_7d ──────────────────────────

    private function evaluateInvoiceOverdue7d(): void
    {
        $invoices = Invoice::withoutCompanyScope()
            ->whereIn('status', ['overdue', 'open'])
            ->where('due_at', '<', now()->subDays(7))
            ->where('due_at', '>=', now()->subDays(30))
            ->get();

        foreach ($invoices as $invoice) {
            $fingerprint = PlatformAlert::fingerprint('billing', 'invoice_overdue_7d', Invoice::class, $invoice->id);

            PlatformAlert::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'source' => 'billing',
                    'type' => 'invoice_overdue_7d',
                    'severity' => 'warning',
                    'status' => 'active',
                    'company_id' => $invoice->company_id,
                    'title' => "Invoice {$invoice->displayNumber()} overdue > 7 days",
                    'description' => "Invoice due on {$invoice->due_at->format('Y-m-d')} is overdue by ".$invoice->due_at->diffInDays(now()).' days.',
                    'metadata' => [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->displayNumber(),
                        'amount_due' => $invoice->amount_due,
                        'currency' => $invoice->currency,
                        'due_at' => $invoice->due_at->toIso8601String(),
                    ],
                    'target_type' => Invoice::class,
                    'target_id' => $invoice->id,
                ]
            );

            $this->created++;
        }
    }

    // ── Rule: invoice_overdue_30d ─────────────────────────

    private function evaluateInvoiceOverdue30d(): void
    {
        $invoices = Invoice::withoutCompanyScope()
            ->whereIn('status', ['overdue', 'open'])
            ->where('due_at', '<', now()->subDays(30))
            ->get();

        foreach ($invoices as $invoice) {
            $fingerprint = PlatformAlert::fingerprint('billing', 'invoice_overdue_30d', Invoice::class, $invoice->id);

            PlatformAlert::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'source' => 'billing',
                    'type' => 'invoice_overdue_30d',
                    'severity' => 'critical',
                    'status' => 'active',
                    'company_id' => $invoice->company_id,
                    'title' => "Invoice {$invoice->displayNumber()} overdue > 30 days",
                    'description' => "Invoice due on {$invoice->due_at->format('Y-m-d')} is overdue by ".$invoice->due_at->diffInDays(now()).' days. Immediate action required.',
                    'metadata' => [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->displayNumber(),
                        'amount_due' => $invoice->amount_due,
                        'currency' => $invoice->currency,
                        'due_at' => $invoice->due_at->toIso8601String(),
                    ],
                    'target_type' => Invoice::class,
                    'target_id' => $invoice->id,
                ]
            );

            $this->created++;
        }
    }

    // ── Rule: payment_failed_3x ───────────────────────────

    private function evaluatePaymentFailed3x(): void
    {
        // Find companies with 3+ failed payments in last 7 days
        $companies = Payment::withoutCompanyScope()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('company_id')
            ->havingRaw('COUNT(*) >= 3')
            ->select('company_id', DB::raw('COUNT(*) as fail_count'))
            ->get();

        foreach ($companies as $row) {
            $fingerprint = PlatformAlert::fingerprint('billing', 'payment_failed_3x', 'company', $row->company_id);

            PlatformAlert::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'source' => 'billing',
                    'type' => 'payment_failed_3x',
                    'severity' => 'critical',
                    'status' => 'active',
                    'company_id' => $row->company_id,
                    'title' => "{$row->fail_count} failed payments in 7 days",
                    'description' => "Company #{$row->company_id} has {$row->fail_count} failed payment attempts in the last 7 days. Review payment methods.",
                    'metadata' => [
                        'fail_count' => $row->fail_count,
                        'period' => '7d',
                    ],
                    'target_type' => null,
                    'target_id' => null,
                ]
            );

            $this->created++;
        }
    }

    // ── Rule: ticket_unassigned_24h ───────────────────────

    private function evaluateTicketUnassigned24h(): void
    {
        $tickets = SupportTicket::withoutCompanyScope()
            ->whereNull('assigned_to_platform_user_id')
            ->where('created_at', '<', now()->subHours(24))
            ->whereNotIn('status', ['closed', 'resolved'])
            ->get();

        foreach ($tickets as $ticket) {
            $fingerprint = PlatformAlert::fingerprint('support', 'ticket_unassigned_24h', SupportTicket::class, $ticket->id);

            PlatformAlert::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'source' => 'support',
                    'type' => 'ticket_unassigned_24h',
                    'severity' => 'info',
                    'status' => 'active',
                    'company_id' => $ticket->company_id,
                    'title' => "Ticket \"{$ticket->subject}\" unassigned > 24h",
                    'description' => "Support ticket #{$ticket->id} created on {$ticket->created_at->format('Y-m-d H:i')} has no assignee.",
                    'metadata' => [
                        'ticket_id' => $ticket->id,
                        'ticket_uuid' => $ticket->uuid,
                        'subject' => $ticket->subject,
                        'priority' => $ticket->priority,
                        'hours_unassigned' => $ticket->created_at->diffInHours(now()),
                    ],
                    'target_type' => SupportTicket::class,
                    'target_id' => $ticket->id,
                ]
            );

            $this->created++;
        }
    }

    // ── Rule: subscription_pending ────────────────────────

    private function evaluateSubscriptionsPending(): void
    {
        $subscriptions = Subscription::withoutCompanyScope()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours(24))
            ->get();

        foreach ($subscriptions as $subscription) {
            $fingerprint = PlatformAlert::fingerprint('billing', 'subscription_pending', Subscription::class, $subscription->id);

            PlatformAlert::updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'source' => 'billing',
                    'type' => 'subscription_pending',
                    'severity' => 'warning',
                    'status' => 'active',
                    'company_id' => $subscription->company_id,
                    'title' => "Subscription #{$subscription->id} pending > 24h",
                    'description' => "Subscription for plan {$subscription->plan_key} has been pending since {$subscription->created_at->format('Y-m-d H:i')}.",
                    'metadata' => [
                        'subscription_id' => $subscription->id,
                        'plan_key' => $subscription->plan_key,
                        'interval' => $subscription->interval,
                        'hours_pending' => $subscription->created_at->diffInHours(now()),
                    ],
                    'target_type' => Subscription::class,
                    'target_id' => $subscription->id,
                ]
            );

            $this->created++;
        }
    }

    // ── Auto-resolve stale alerts ─────────────────────────

    /**
     * Auto-resolve alerts whose conditions are no longer true.
     * Only resolves alerts that were NOT manually acknowledged/dismissed.
     */
    private function autoResolveStaleAlerts(): void
    {
        // Resolve invoice_overdue alerts where invoice is no longer overdue/open
        $this->autoResolveByTarget(
            'billing',
            ['invoice_overdue_7d', 'invoice_overdue_30d'],
            Invoice::class,
            fn ($invoiceIds) => Invoice::withoutCompanyScope()
                ->whereIn('id', $invoiceIds)
                ->whereNotIn('status', ['overdue', 'open'])
                ->pluck('id')
                ->all()
        );

        // Resolve ticket_unassigned_24h where ticket is now assigned or closed
        $this->autoResolveByTarget(
            'support',
            ['ticket_unassigned_24h'],
            SupportTicket::class,
            fn ($ticketIds) => SupportTicket::withoutCompanyScope()
                ->whereIn('id', $ticketIds)
                ->where(function ($q) {
                    $q->whereNotNull('assigned_to_platform_user_id')
                        ->orWhereIn('status', ['closed', 'resolved']);
                })
                ->pluck('id')
                ->all()
        );

        // Resolve subscription_pending where subscription is no longer pending
        $this->autoResolveByTarget(
            'billing',
            ['subscription_pending'],
            Subscription::class,
            fn ($subIds) => Subscription::withoutCompanyScope()
                ->whereIn('id', $subIds)
                ->where('status', '!=', 'pending')
                ->pluck('id')
                ->all()
        );

        // Resolve payment_failed_3x where company no longer has 3+ failed payments in 7d
        $activePaymentAlerts = PlatformAlert::active()
            ->where('type', 'payment_failed_3x')
            ->get();

        foreach ($activePaymentAlerts as $alert) {
            $failCount = Payment::withoutCompanyScope()
                ->where('company_id', $alert->company_id)
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            if ($failCount < 3) {
                $alert->resolve();
                $this->resolved++;
            }
        }
    }

    /**
     * Generic auto-resolve: find active alerts for given types/target_type,
     * check which targets are "resolved" via the callback, and resolve those alerts.
     */
    private function autoResolveByTarget(string $source, array $types, string $targetType, callable $resolvedIdsFinder): void
    {
        $activeAlerts = PlatformAlert::active()
            ->where('source', $source)
            ->whereIn('type', $types)
            ->where('target_type', $targetType)
            ->whereNotNull('target_id')
            ->get();

        if ($activeAlerts->isEmpty()) {
            return;
        }

        $targetIds = $activeAlerts->pluck('target_id')->unique()->all();
        $resolvedIds = $resolvedIdsFinder($targetIds);

        foreach ($activeAlerts as $alert) {
            if (in_array($alert->target_id, $resolvedIds)) {
                $alert->resolve();
                $this->resolved++;
            }
        }
    }
}
