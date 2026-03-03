<?php

namespace Database\Seeders;

use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Billing demo data — realistic financial records for dashboard widgets.
 *
 * Creates 90 days of invoices, payments, credit notes, and ledger entries
 * across multiple companies so all 12 billing dashboard widgets display
 * meaningful data (charts, KPIs, activity lists, risk indicators).
 *
 * 100% idempotent: skips if demo invoices already exist.
 */
class BillingDemoSeeder extends Seeder
{
    private const CURRENCY = 'EUR';

    public function run(): void
    {
        // Safety: never run in production (belt-and-suspenders, DevSeeder is already gated)
        if (app()->environment('production')) {
            $this->command?->warn('BillingDemoSeeder skipped — production environment.');
            return;
        }

        // Idempotency: skip if already seeded
        if (Invoice::where('number', 'LIKE', 'DEMO-%')->exists()) {
            return;
        }

        // ── Companies ─────────────────────────────────────────────
        $companies = $this->ensureCompanies();

        // ── Generate financial data per company ───────────────────
        foreach ($companies as $spec) {
            $this->seedCompanyBilling($spec['company'], $spec['plan'], $spec['lines']);
        }
    }

    /**
     * Ensure demo companies exist with subscriptions.
     *
     * @return array<array{company: Company, plan: string, lines: array}>
     */
    private function ensureCompanies(): array
    {
        $main = Company::where('slug', 'leezr-logistics')->first();

        $c2 = Company::updateOrCreate(
            ['slug' => 'transfret-express'],
            ['name' => 'TransFret Express', 'plan_key' => 'business', 'market_key' => 'FR', 'jobdomain_key' => 'logistique'],
        );

        $c3 = Company::updateOrCreate(
            ['slug' => 'colistech'],
            ['name' => 'ColisTech', 'plan_key' => 'starter', 'market_key' => 'FR', 'jobdomain_key' => 'logistique'],
        );

        // Ensure subscriptions
        foreach ([$c2, $c3] as $c) {
            Subscription::updateOrCreate(
                ['company_id' => $c->id, 'plan_key' => $c->plan_key],
                [
                    'interval' => 'monthly',
                    'status' => 'active',
                    'provider' => 'internal',
                    'current_period_start' => now()->startOfMonth(),
                    'current_period_end' => now()->endOfMonth(),
                ],
            );
        }

        $specs = [];

        if ($main) {
            $specs[] = [
                'company' => $main,
                'plan' => 'pro',
                'lines' => [
                    ['type' => 'plan', 'desc' => 'Pro Plan (monthly)', 'amount' => 29900],
                    ['type' => 'addon', 'desc' => 'Real-Time Tracking', 'amount' => 2900],
                ],
            ];
        }

        $specs[] = [
            'company' => $c2,
            'plan' => 'business',
            'lines' => [
                ['type' => 'plan', 'desc' => 'Business Plan (monthly)', 'amount' => 49900],
                ['type' => 'addon', 'desc' => 'Fleet Management', 'amount' => 4900],
                ['type' => 'addon', 'desc' => 'Advanced Analytics', 'amount' => 1900],
            ],
        ];

        $specs[] = [
            'company' => $c3,
            'plan' => 'starter',
            'lines' => [
                ['type' => 'plan', 'desc' => 'Starter Plan (monthly)', 'amount' => 14900],
            ],
        ];

        return $specs;
    }

    /**
     * Seed 3 months of billing data for a single company.
     */
    private function seedCompanyBilling(Company $company, string $plan, array $lineSpecs): void
    {
        $companyId = $company->id;
        $taxRateBps = 2000; // 20% VAT

        // ── Generate monthly invoices for the last 3 months ───────
        for ($m = 2; $m >= 0; $m--) {
            $monthStart = now()->subMonths($m)->startOfMonth();
            $monthEnd = now()->subMonths($m)->endOfMonth();
            $invoiceDate = $monthStart->copy()->addDays(rand(0, 3));
            $dueDate = $invoiceDate->copy()->addDays(30);
            $seqNum = 3 - $m; // 1, 2, 3
            $invoiceNumber = sprintf('DEMO-%s-%03d', strtoupper(Str::slug($company->slug)), $seqNum);

            // Calculate totals
            $subtotal = collect($lineSpecs)->sum('amount');

            // Add some variance (±10%)
            $variance = (int) ($subtotal * rand(-10, 10) / 100);
            $subtotal += $variance;

            $taxAmount = (int) ($subtotal * $taxRateBps / 10000);
            $total = $subtotal + $taxAmount;

            // Status: older invoices paid, current month open
            $isPaid = $m > 0;
            $paidAt = $isPaid ? $invoiceDate->copy()->addDays(rand(1, 10)) : null;

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'number' => $invoiceNumber,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'tax_rate_bps' => $taxRateBps,
                'amount' => $total,
                'wallet_credit_applied' => 0,
                'amount_due' => $total,
                'currency' => self::CURRENCY,
                'status' => $isPaid ? 'paid' : 'open',
                'provider' => 'internal',
                'period_start' => $monthStart,
                'period_end' => $monthEnd,
                'issued_at' => $invoiceDate,
                'due_at' => $dueDate,
                'paid_at' => $paidAt,
                'finalized_at' => $invoiceDate,
            ]);

            // Invoice lines
            foreach ($lineSpecs as $line) {
                $lineAmount = $line['amount'] + (int) ($line['amount'] * rand(-10, 10) / 100);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'type' => $line['type'],
                    'description' => $line['desc'],
                    'quantity' => 1,
                    'unit_amount' => $lineAmount,
                    'amount' => $lineAmount,
                    'period_start' => $monthStart,
                    'period_end' => $monthEnd,
                    'created_at' => $invoiceDate,
                ]);
            }

            // Ledger: invoice issued → DR AR, CR REVENUE
            $corrId = Str::uuid()->toString();
            $subtotalDecimal = $subtotal / 100;

            LedgerEntry::create([
                'company_id' => $companyId,
                'entry_type' => 'invoice_issued',
                'account_code' => 'AR',
                'debit' => $subtotalDecimal,
                'credit' => 0,
                'currency' => self::CURRENCY,
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
                'correlation_id' => $corrId,
                'recorded_at' => $invoiceDate,
            ]);

            LedgerEntry::create([
                'company_id' => $companyId,
                'entry_type' => 'invoice_issued',
                'account_code' => 'REVENUE',
                'debit' => 0,
                'credit' => $subtotalDecimal,
                'currency' => self::CURRENCY,
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
                'correlation_id' => $corrId,
                'recorded_at' => $invoiceDate,
            ]);

            // Payment for paid invoices
            if ($isPaid) {
                $payment = Payment::create([
                    'invoice_id' => $invoice->id,
                    'company_id' => $companyId,
                    'amount' => $total,
                    'currency' => self::CURRENCY,
                    'status' => 'succeeded',
                    'provider' => 'internal',
                    'provider_payment_id' => 'demo_pay_' . $invoice->number,
                    'created_at' => $paidAt,
                    'updated_at' => $paidAt,
                ]);

                // Ledger: payment received → DR CASH, CR AR
                $payCorrId = Str::uuid()->toString();
                $totalDecimal = $total / 100;

                LedgerEntry::create([
                    'company_id' => $companyId,
                    'entry_type' => 'payment_received',
                    'account_code' => 'CASH',
                    'debit' => $totalDecimal,
                    'credit' => 0,
                    'currency' => self::CURRENCY,
                    'reference_type' => 'payment',
                    'reference_id' => $payment->id,
                    'correlation_id' => $payCorrId,
                    'recorded_at' => $paidAt,
                ]);

                LedgerEntry::create([
                    'company_id' => $companyId,
                    'entry_type' => 'payment_received',
                    'account_code' => 'AR',
                    'debit' => 0,
                    'credit' => $totalDecimal,
                    'currency' => self::CURRENCY,
                    'reference_type' => 'payment',
                    'reference_id' => $payment->id,
                    'correlation_id' => $payCorrId,
                    'recorded_at' => $paidAt,
                ]);
            }
        }

        // ── Failed payments (for risk widgets) ────────────────────
        $failureReasons = ['card_declined', 'insufficient_funds', 'expired_card', 'processing_error'];

        for ($i = 0; $i < 2; $i++) {
            $failDate = now()->subDays(rand(1, 6));

            Payment::create([
                'company_id' => $companyId,
                'amount' => rand(10000, 50000),
                'currency' => self::CURRENCY,
                'status' => 'failed',
                'provider' => 'internal',
                'provider_payment_id' => sprintf('demo_fail_%s_%d', Str::slug($company->slug), $i),
                'metadata' => ['failure_reason' => $failureReasons[array_rand($failureReasons)]],
                'created_at' => $failDate,
                'updated_at' => $failDate,
            ]);
        }

        // ── Overdue invoice (for dunning / pending_dunning widget) ──
        $overdueDate = now()->subDays(45);

        Invoice::create([
            'company_id' => $companyId,
            'number' => sprintf('DEMO-%s-OVERDUE', strtoupper(Str::slug($company->slug))),
            'subtotal' => 19900,
            'tax_amount' => 3980,
            'tax_rate_bps' => $taxRateBps,
            'amount' => 23880,
            'wallet_credit_applied' => 0,
            'amount_due' => 23880,
            'currency' => self::CURRENCY,
            'status' => 'open',
            'provider' => 'internal',
            'period_start' => $overdueDate->copy()->startOfMonth(),
            'period_end' => $overdueDate->copy()->endOfMonth(),
            'issued_at' => $overdueDate,
            'due_at' => $overdueDate->copy()->addDays(15), // due ~30 days ago
            'paid_at' => null,
            'finalized_at' => $overdueDate,
        ]);

        // ── Credit note / refund (for refund ratio widget) ────────
        $refundDate = now()->subDays(rand(5, 20));
        $refundAmount = rand(5000, 15000); // €50-150

        $creditNote = CreditNote::create([
            'number' => sprintf('DEMO-CN-%s-001', strtoupper(Str::slug($company->slug))),
            'company_id' => $companyId,
            'amount' => $refundAmount,
            'currency' => self::CURRENCY,
            'reason' => 'Service credit — billing adjustment',
            'status' => 'issued',
            'issued_at' => $refundDate,
        ]);

        // Ledger: refund issued → DR REFUND, CR CASH
        $refCorrId = Str::uuid()->toString();
        $refundDecimal = $refundAmount / 100;

        LedgerEntry::create([
            'company_id' => $companyId,
            'entry_type' => 'refund_issued',
            'account_code' => 'REFUND',
            'debit' => $refundDecimal,
            'credit' => 0,
            'currency' => self::CURRENCY,
            'reference_type' => 'credit_note',
            'reference_id' => $creditNote->id,
            'correlation_id' => $refCorrId,
            'recorded_at' => $refundDate,
        ]);

        LedgerEntry::create([
            'company_id' => $companyId,
            'entry_type' => 'refund_issued',
            'account_code' => 'CASH',
            'debit' => 0,
            'credit' => $refundDecimal,
            'currency' => self::CURRENCY,
            'reference_type' => 'credit_note',
            'reference_id' => $creditNote->id,
            'correlation_id' => $refCorrId,
            'recorded_at' => $refundDate,
        ]);
    }
}
