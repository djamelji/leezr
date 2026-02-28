<?php

namespace Database\Seeders;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\FinancialSnapshot;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Finance demo data for D4 stabilisation.
 *
 * Creates a complete financial scenario for Company #1:
 *   - Invoice #1 finalized (10000c) → payment → partial refund (3000c)
 *   - Invoice #2 finalized (5000c) → write-off
 *   - Drift audit log
 *   - Financial snapshot
 *
 * All writes go through Core services — never inserts into ledger directly.
 */
class FinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        if (! $company) {
            $this->command->warn('No company found — skipping FinanceDemoSeeder.');

            return;
        }

        $this->command->info("Seeding finance demo for Company #{$company->id} ({$company->name})");

        // ── 1. Invoice #1 — 10000 cents (€100) ──────────────────────
        $invoice1 = InvoiceIssuer::createDraft($company);

        InvoiceIssuer::addLine(
            invoice: $invoice1,
            type: 'subscription',
            description: 'Demo monthly subscription',
            unitAmount: 10000,
            quantity: 1,
        );

        $invoice1 = InvoiceIssuer::finalize($invoice1);
        // → LedgerService::recordInvoiceIssued called automatically inside finalize()

        $this->command->info("  Invoice #{$invoice1->number} finalized — {$invoice1->amount_due}c");

        // ── 2. Payment for Invoice #1 ────────────────────────────────
        $payment = Payment::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice1->id,
            'provider' => 'internal',
            'provider_payment_id' => 'demo_pay_' . Str::random(8),
            'amount' => $invoice1->amount_due,
            'currency' => $invoice1->currency,
            'status' => 'succeeded',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $invoice1->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->command->info("  Payment recorded — {$payment->amount}c via {$payment->provider}");

        // ── 3. Partial refund — 3000 cents (€30) ────────────────────
        $creditNote = CreditNoteIssuer::createDraft(
            company: $company,
            amount: 3000,
            reason: 'Demo partial refund',
            invoiceId: $invoice1->id,
        );

        $creditNote = CreditNoteIssuer::issue($creditNote);

        LedgerService::recordRefundIssued($creditNote);

        $this->command->info("  Credit note #{$creditNote->number} issued — 3000c");

        // ── 4. Invoice #2 — 5000 cents (€50) → write-off ────────────
        $invoice2 = InvoiceIssuer::createDraft($company);

        InvoiceIssuer::addLine(
            invoice: $invoice2,
            type: 'addon',
            description: 'Demo add-on charge',
            unitAmount: 5000,
            quantity: 1,
        );

        $invoice2 = InvoiceIssuer::finalize($invoice2);
        // → LedgerService::recordInvoiceIssued called automatically

        $invoice2->update([
            'status' => 'uncollectible',
        ]);

        LedgerService::recordWriteOff($invoice2);

        $this->command->info("  Invoice #{$invoice2->number} written off — {$invoice2->amount_due}c");

        // ── 5. Drift audit log ───────────────────────────────────────
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

        $this->command->info('  Drift audit log created');

        // ── 6. Financial snapshot ────────────────────────────────────
        FinancialSnapshot::create([
            'company_id' => $company->id,
            'trigger' => 'seed',
            'drift_type' => 'missing_local_payment',
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'snapshot_data' => [
                'invoice_id' => $invoice1->id,
                'amount' => $invoice1->amount_due,
                'status_before' => 'open',
                'status_after' => 'paid',
            ],
            'correlation_id' => Str::uuid()->toString(),
            'created_at' => now(),
        ]);

        $this->command->info('  Financial snapshot created');

        $this->command->newLine();
        $this->command->info('Finance demo seed complete.');
    }
}
