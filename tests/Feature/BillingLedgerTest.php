<?php

namespace Tests\Feature;

use App\Core\Billing\AdminAdvancedMutationService;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CreditNote;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-142 D3f: Immutable Financial Ledger.
 *
 * 30 tests: 10 double-entry, 5 immutability, 5 hook integration, 5 trial balance, 5 integrity command.
 */
class BillingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Subscription $subscription;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Ledger Co',
            'slug' => 'ledger-co',
            'plan_key' => 'pro',
            'status' => 'active',
        ]);
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test_ledger',
        ]);

        // Finalized invoice (2900 cents = 29.00)
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $this->invoice = InvoiceIssuer::finalize($draft);

        config(['billing.alerting.enabled' => false]);

        $this->bindMockAdapter();
    }

    private function bindMockAdapter(): void
    {
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeListPaymentIntents(string $customerId, int $sinceTimestamp): array
                {
                    return [];
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata): \Stripe\PaymentIntent
                {
                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_mock_' . uniqid(),
                        'amount' => $amount,
                        'amount_received' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
                {
                    return \Stripe\Refund::constructFrom([
                        'id' => 're_mock_' . uniqid(),
                        'amount' => $amount,
                        'status' => 'succeeded',
                    ]);
                }
            };
        });
    }

    // ═══════════════════════════════════════════════════════════
    // A) Double-entry correctness (10 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_invoice_issued_creates_two_entries(): void
    {
        // Invoice was finalized in setUp — check ledger entries were created
        $entries = LedgerEntry::where('reference_type', 'invoice')
            ->where('reference_id', $this->invoice->id)
            ->where('entry_type', 'invoice_issued')
            ->get();

        $this->assertCount(2, $entries);
    }

    public function test_invoice_issued_debit_equals_credit(): void
    {
        $entries = LedgerEntry::where('reference_type', 'invoice')
            ->where('reference_id', $this->invoice->id)
            ->where('entry_type', 'invoice_issued')
            ->get();

        $totalDebit = $entries->sum('debit');
        $totalCredit = $entries->sum('credit');

        $this->assertEquals($totalDebit, $totalCredit);
    }

    public function test_invoice_issued_debits_ar(): void
    {
        $ar = LedgerEntry::where('reference_id', $this->invoice->id)
            ->where('entry_type', 'invoice_issued')
            ->where('account_code', 'AR')
            ->first();

        $this->assertNotNull($ar);
        $this->assertGreaterThan(0, (float) $ar->debit);
        $this->assertEquals(0, (float) $ar->credit);
    }

    public function test_invoice_issued_credits_revenue(): void
    {
        $revenue = LedgerEntry::where('reference_id', $this->invoice->id)
            ->where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->first();

        $this->assertNotNull($revenue);
        $this->assertEquals(0, (float) $revenue->debit);
        $this->assertGreaterThan(0, (float) $revenue->credit);
    }

    public function test_payment_received_creates_two_entries(): void
    {
        $payment = Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_ledger_test',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $entries = LedgerEntry::where('entry_type', 'payment_received')
            ->where('reference_id', $payment->id)
            ->get();

        $this->assertCount(2, $entries);
        $this->assertEquals($entries->sum('debit'), $entries->sum('credit'));
    }

    public function test_payment_received_debits_cash_credits_ar(): void
    {
        $payment = Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_ledger_cash',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $cash = LedgerEntry::where('entry_type', 'payment_received')
            ->where('reference_id', $payment->id)
            ->where('account_code', 'CASH')
            ->first();

        $ar = LedgerEntry::where('entry_type', 'payment_received')
            ->where('reference_id', $payment->id)
            ->where('account_code', 'AR')
            ->first();

        $this->assertGreaterThan(0, (float) $cash->debit);
        $this->assertGreaterThan(0, (float) $ar->credit);
    }

    public function test_refund_issued_creates_two_entries(): void
    {
        $cn = CreditNoteIssuer::createDraft($this->company, 500, 'test refund', $this->invoice->id);
        $cn = CreditNoteIssuer::issue($cn);

        LedgerService::recordRefundIssued($cn);

        $entries = LedgerEntry::where('entry_type', 'refund_issued')
            ->where('reference_id', $cn->id)
            ->get();

        $this->assertCount(2, $entries);
        $this->assertEquals($entries->sum('debit'), $entries->sum('credit'));
    }

    public function test_refund_issued_debits_refund_credits_cash(): void
    {
        $cn = CreditNoteIssuer::createDraft($this->company, 500, 'test refund', $this->invoice->id);
        $cn = CreditNoteIssuer::issue($cn);

        LedgerService::recordRefundIssued($cn);

        $refund = LedgerEntry::where('entry_type', 'refund_issued')
            ->where('account_code', 'REFUND')
            ->first();
        $cash = LedgerEntry::where('entry_type', 'refund_issued')
            ->where('account_code', 'CASH')
            ->first();

        $this->assertGreaterThan(0, (float) $refund->debit);
        $this->assertGreaterThan(0, (float) $cash->credit);
    }

    public function test_writeoff_creates_two_entries(): void
    {
        LedgerService::recordWriteOff($this->invoice);

        $entries = LedgerEntry::where('entry_type', 'writeoff')
            ->where('reference_id', $this->invoice->id)
            ->get();

        $this->assertCount(2, $entries);
        $this->assertEquals($entries->sum('debit'), $entries->sum('credit'));
    }

    public function test_writeoff_debits_bad_debt_credits_ar(): void
    {
        LedgerService::recordWriteOff($this->invoice);

        $badDebt = LedgerEntry::where('entry_type', 'writeoff')
            ->where('account_code', 'BAD_DEBT')
            ->first();
        $ar = LedgerEntry::where('entry_type', 'writeoff')
            ->where('account_code', 'AR')
            ->first();

        $this->assertGreaterThan(0, (float) $badDebt->debit);
        $this->assertGreaterThan(0, (float) $ar->credit);
    }

    // ═══════════════════════════════════════════════════════════
    // B) Immutability (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_ledger_entry_cannot_be_updated(): void
    {
        $entry = LedgerEntry::first();
        $this->assertNotNull($entry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->update(['debit' => 999.99]);
    }

    public function test_ledger_entry_cannot_be_deleted(): void
    {
        $entry = LedgerEntry::first();
        $this->assertNotNull($entry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->delete();
    }

    public function test_ledger_entry_save_on_existing_throws(): void
    {
        $entry = LedgerEntry::first();
        $entry->debit = 999.99;

        $this->expectException(\RuntimeException::class);
        $entry->save();
    }

    public function test_new_ledger_entry_can_be_created(): void
    {
        $count = LedgerEntry::count();
        $this->assertGreaterThan(0, $count);

        // Creating new entries is always allowed
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'adjustment',
            'account_code' => 'AR',
            'debit' => 10.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => $this->invoice->id,
            'correlation_id' => \Illuminate\Support\Str::uuid()->toString(),
            'recorded_at' => now(),
        ]);

        $this->assertEquals($count + 1, LedgerEntry::count());
    }

    public function test_ledger_entry_has_timestamps(): void
    {
        $entry = LedgerEntry::first();
        $this->assertNotNull($entry->created_at);
        $this->assertNotNull($entry->recorded_at);
    }

    // ═══════════════════════════════════════════════════════════
    // C) Hook integration (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_finalize_invoice_creates_ledger_entries(): void
    {
        // New invoice
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Test plan', 5000);
        $invoice = InvoiceIssuer::finalize($draft);

        $entries = LedgerEntry::where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)
            ->where('entry_type', 'invoice_issued')
            ->get();

        $this->assertCount(2, $entries);
    }

    public function test_webhook_payment_succeeded_creates_ledger_entries(): void
    {
        $processor = app(\App\Core\Billing\Stripe\StripeEventProcessor::class);

        $payload = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_webhook_ledger',
                    'amount' => 2900,
                    'amount_received' => 2900,
                    'currency' => 'eur',
                    'customer' => 'cus_test_ledger',
                    'metadata' => [
                        'invoice_id' => (string) $this->invoice->id,
                        'company_id' => (string) $this->company->id,
                    ],
                ],
            ],
        ];

        $result = $processor->process($payload);
        $this->assertTrue($result->handled);

        $payment = Payment::where('provider_payment_id', 'pi_webhook_ledger')->first();
        $this->assertNotNull($payment);

        $entries = LedgerEntry::where('entry_type', 'payment_received')
            ->where('reference_type', 'payment')
            ->where('reference_id', $payment->id)
            ->get();

        $this->assertCount(2, $entries);
    }

    public function test_admin_refund_creates_ledger_entries(): void
    {
        // Pay the invoice first
        $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);

        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_refund_ledger',
        ]);

        $service = app(AdminAdvancedMutationService::class);
        $result = $service->refund($this->invoice, 500, 'Test refund', 'idem-refund-ledger');

        $cn = $result['credit_note'];

        $entries = LedgerEntry::where('entry_type', 'refund_issued')
            ->where('reference_type', 'credit_note')
            ->where('reference_id', $cn->id)
            ->get();

        $this->assertCount(2, $entries);
    }

    public function test_admin_writeoff_creates_ledger_entries(): void
    {
        $this->invoice->update(['status' => 'overdue']);

        $service = app(AdminAdvancedMutationService::class);
        $result = $service->writeOff($this->invoice, 'idem-writeoff-ledger');

        $entries = LedgerEntry::where('entry_type', 'writeoff')
            ->where('reference_type', 'invoice')
            ->where('reference_id', $this->invoice->id)
            ->get();

        $this->assertCount(2, $entries);
    }

    public function test_dunning_exhaustion_creates_writeoff_ledger(): void
    {
        // Override adapter to return failed — so provider doesn't intercept
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeListPaymentIntents(string $customerId, int $sinceTimestamp): array
                {
                    return [];
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata): \Stripe\PaymentIntent
                {
                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_mock_fail',
                        'amount' => $amount,
                        'amount_received' => 0,
                        'currency' => $currency,
                        'status' => 'requires_payment_method', // Not succeeded
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
                {
                    return \Stripe\Refund::constructFrom([
                        'id' => 're_mock_fail',
                        'amount' => $amount,
                        'status' => 'succeeded',
                    ]);
                }
            };
        });

        // Ensure max_retry_attempts = 1 so it exhausts immediately
        PlatformBillingPolicy::instance()->update([
            'max_retry_attempts' => 1,
            'retry_intervals_days' => [1],
        ]);

        // Set up for dunning: overdue invoice at retry_count=0
        $this->invoice->update([
            'status' => 'overdue',
            'retry_count' => 0,
            'next_retry_at' => now()->subDay(),
        ]);

        $this->subscription->update(['status' => 'past_due']);

        // Process dunning — provider fails, wallet empty → exhausted
        DunningEngine::processOverdueInvoices();

        $this->invoice->refresh();
        $this->assertEquals('uncollectible', $this->invoice->status);

        $entries = LedgerEntry::where('entry_type', 'writeoff')
            ->where('reference_type', 'invoice')
            ->where('reference_id', $this->invoice->id)
            ->get();

        $this->assertCount(2, $entries);
    }

    // ═══════════════════════════════════════════════════════════
    // D) Trial balance math (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_trial_balance_returns_all_accounts(): void
    {
        $balance = LedgerService::trialBalance($this->company->id);

        $this->assertArrayHasKey('AR', $balance);
        $this->assertArrayHasKey('CASH', $balance);
        $this->assertArrayHasKey('REVENUE', $balance);
        $this->assertArrayHasKey('REFUND', $balance);
        $this->assertArrayHasKey('BAD_DEBT', $balance);
    }

    public function test_trial_balance_after_invoice(): void
    {
        $balance = LedgerService::trialBalance($this->company->id);

        // After invoice issued: AR has positive balance, REVENUE has negative
        $expectedAmount = round($this->invoice->amount_due / 100, 2);
        $this->assertEquals($expectedAmount, $balance['AR']);
        $this->assertEquals(-$expectedAmount, $balance['REVENUE']);
    }

    public function test_trial_balance_after_payment_clears_ar(): void
    {
        $payment = Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => $this->invoice->amount_due,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_balance_test',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $balance = LedgerService::trialBalance($this->company->id);

        // AR should be 0 (invoice debit + payment credit cancel out)
        $this->assertEquals(0, $balance['AR']);
        // CASH should equal the payment
        $expectedAmount = round($this->invoice->amount_due / 100, 2);
        $this->assertEquals($expectedAmount, $balance['CASH']);
    }

    public function test_trial_balance_after_refund(): void
    {
        // Issue + pay + refund
        $payment = Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_refund_balance',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $cn = CreditNoteIssuer::createDraft($this->company, 500, 'partial refund', $this->invoice->id);
        $cn = CreditNoteIssuer::issue($cn);

        LedgerService::recordRefundIssued($cn);

        $balance = LedgerService::trialBalance($this->company->id);

        // REFUND should have positive balance
        $this->assertEquals(5.00, $balance['REFUND']);
        // CASH = payment - refund
        $this->assertEquals(24.00, $balance['CASH']);
    }

    public function test_trial_balance_after_writeoff(): void
    {
        LedgerService::recordWriteOff($this->invoice);

        $balance = LedgerService::trialBalance($this->company->id);

        // AR should be 0 (invoice debit + writeoff credit cancel out)
        $this->assertEquals(0, $balance['AR']);
        // BAD_DEBT should equal the amount
        $expectedAmount = round($this->invoice->amount_due / 100, 2);
        $this->assertEquals($expectedAmount, $balance['BAD_DEBT']);
    }

    // ═══════════════════════════════════════════════════════════
    // E) Integrity command (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_integrity_check_passes_clean_ledger(): void
    {
        $this->artisan('billing:ledger-check')
            ->expectsOutputToContain('no violations')
            ->assertExitCode(0);
    }

    public function test_integrity_check_detects_imbalance(): void
    {
        // Manually insert an imbalanced entry
        $correlationId = \Illuminate\Support\Str::uuid()->toString();

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'adjustment',
            'account_code' => 'AR',
            'debit' => 100.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => $this->invoice->id,
            'correlation_id' => $correlationId,
            'recorded_at' => now(),
        ]);

        // Only one side — imbalanced
        $this->artisan('billing:ledger-check')
            ->expectsOutputToContain('violation')
            ->assertExitCode(1);
    }

    public function test_integrity_check_detects_orphan_reference(): void
    {
        $correlationId = \Illuminate\Support\Str::uuid()->toString();

        // Reference a non-existent invoice
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'AR',
            'debit' => 50.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 999999,
            'correlation_id' => $correlationId,
            'recorded_at' => now(),
        ]);
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'REVENUE',
            'debit' => 0,
            'credit' => 50.00,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 999999,
            'correlation_id' => $correlationId,
            'recorded_at' => now(),
        ]);

        $this->artisan('billing:ledger-check')
            ->expectsOutputToContain('orphan_reference')
            ->assertExitCode(1);
    }

    public function test_integrity_check_detects_currency_mismatch(): void
    {
        $correlationId = \Illuminate\Support\Str::uuid()->toString();

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'adjustment',
            'account_code' => 'AR',
            'debit' => 50.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => $this->invoice->id,
            'correlation_id' => $correlationId,
            'recorded_at' => now(),
        ]);
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'adjustment',
            'account_code' => 'REVENUE',
            'debit' => 0,
            'credit' => 50.00,
            'currency' => 'USD', // Different currency!
            'reference_type' => 'invoice',
            'reference_id' => $this->invoice->id,
            'correlation_id' => $correlationId,
            'recorded_at' => now(),
        ]);

        $this->artisan('billing:ledger-check')
            ->expectsOutputToContain('currency_mismatch')
            ->assertExitCode(1);
    }

    public function test_integrity_check_supports_company_filter(): void
    {
        $this->artisan('billing:ledger-check', ['--company' => $this->company->id])
            ->expectsOutputToContain('no violations')
            ->assertExitCode(0);
    }
}
