<?php

namespace Database\Seeders;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\FinancialSnapshot;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds realistic financial demo data for Leezr Logistics (company_id=2).
 *
 * Creates invoices tied to the subscription with proper periods and due dates,
 * so that the full dunning cycle can be tested end-to-end.
 *
 * All writes go through Core services — never inserts into ledger directly.
 */
class FinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('slug', 'leezr-logistics')->first();

        if (! $company) {
            $this->command->warn('Leezr Logistics not found — skipping FinanceDemoSeeder.');

            return;
        }

        // Skip if already has invoices
        if ($company->invoices()->exists()) {
            $this->command->info("{$company->name} already has invoices — skipping FinanceDemoSeeder.");

            return;
        }

        $subscription = Subscription::where('company_id', $company->id)->first();

        if (! $subscription) {
            $this->command->warn('No subscription for company #2 — skipping FinanceDemoSeeder.');

            return;
        }

        $this->command->info("Seeding finance demo for {$company->name} (sub: {$subscription->plan_key}/{$subscription->interval})");

        // ── 1. Invoice — January (paid) ────────────────────────────────
        $inv1 = $this->createSubscriptionInvoice(
            company: $company,
            subscription: $subscription,
            periodStart: '2026-01-01',
            periodEnd: '2026-01-31',
            issuedAt: '2026-01-01',
            dueAt: '2026-01-31',
        );

        $this->payInvoice($company, $inv1);
        $this->command->info("  {$inv1->number} — paid — {$inv1->amount_due}c (Jan)");

        // ── 2. Invoice — February (paid + partial refund) ──────────────
        $inv2 = $this->createSubscriptionInvoice(
            company: $company,
            subscription: $subscription,
            periodStart: '2026-02-01',
            periodEnd: '2026-02-28',
            issuedAt: '2026-02-01',
            dueAt: '2026-02-28',
        );

        $this->payInvoice($company, $inv2);

        // Partial refund of 30€
        $creditNote = CreditNoteIssuer::createDraft(
            company: $company,
            amount: 3000,
            reason: 'Service interruption credit',
            invoiceId: $inv2->id,
        );

        $creditNote = CreditNoteIssuer::issue($creditNote);
        LedgerService::recordRefundIssued($creditNote);

        $this->command->info("  {$inv2->number} — paid + credit note {$creditNote->number} (3000c) (Feb)");

        // ── 3. Invoice — March (overdue — due_at in the past) ──────────
        $inv3 = $this->createSubscriptionInvoice(
            company: $company,
            subscription: $subscription,
            periodStart: '2026-03-01',
            periodEnd: '2026-03-31',
            issuedAt: '2026-03-01',
            dueAt: '2026-03-05', // Already past due → dunning will pick it up
        );

        $this->command->info("  {$inv3->number} — open (overdue, due 5 mars) — {$inv3->amount_due}c (Mar)");

        // ── 4. Drift audit log ─────────────────────────────────────────
        app(AuditLogger::class)->logPlatform(
            'billing.drift_detected',
            'company',
            (string) $company->id,
            [
                'severity' => 'critical',
                'actorType' => 'system',
                'metadata' => [
                    'company_id' => $company->id,
                    'type' => 'missing_local_payment',
                    'provider_payment_id' => 'pi_demo_drift_' . Str::random(8),
                ],
            ],
        );

        // ── 5. Financial snapshot ──────────────────────────────────────
        FinancialSnapshot::create([
            'company_id' => $company->id,
            'trigger' => 'seed',
            'drift_type' => 'missing_local_payment',
            'entity_type' => 'payment',
            'entity_id' => 0,
            'snapshot_data' => [
                'invoice_id' => $inv1->id,
                'amount' => $inv1->amount_due,
                'status_before' => 'open',
                'status_after' => 'paid',
            ],
            'correlation_id' => Str::uuid()->toString(),
            'created_at' => now(),
        ]);

        $this->command->newLine();
        $this->command->info('Finance demo seed complete.');
    }

    private function createSubscriptionInvoice(
        Company $company,
        Subscription $subscription,
        string $periodStart,
        string $periodEnd,
        string $issuedAt,
        string $dueAt,
    ): \App\Core\Billing\Invoice {
        $draft = InvoiceIssuer::createDraft($company);

        InvoiceIssuer::addLine(
            invoice: $draft,
            type: 'subscription',
            description: ucfirst($subscription->plan_key) . ' plan — ' . $subscription->interval,
            unitAmount: $subscription->interval === 'yearly'
                ? ($subscription->plan->price_yearly ?? 11880)
                : ($subscription->plan->price_monthly ?? 990),
            quantity: 1,
        );

        $finalized = InvoiceIssuer::finalize($draft);

        // Set proper dates and link to subscription
        $finalized->update([
            'subscription_id' => $subscription->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'issued_at' => $issuedAt,
            'due_at' => $dueAt,
        ]);

        $finalized->refresh();

        return $finalized;
    }

    private function payInvoice(Company $company, \App\Core\Billing\Invoice $invoice): void
    {
        $payment = Payment::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'provider' => 'internal',
            'provider_payment_id' => 'demo_pay_' . Str::random(8),
            'amount' => $invoice->amount_due,
            'currency' => $invoice->currency,
            'status' => 'succeeded',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => $invoice->due_at,
        ]);
    }
}
