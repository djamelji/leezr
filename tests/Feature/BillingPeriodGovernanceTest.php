<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\AdminAdvancedMutationService;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CreditNote;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\FinancialPeriod;
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
 * ADR-143 D3g: Period Closing, Ledger Locking & Financial Controls.
 *
 * 35 tests: 8 period closing, 7 ledger guard, 5 adjustment, 5 financial freeze,
 * 5 writeoff threshold, 5 command.
 */
class BillingPeriodGovernanceTest extends TestCase
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
            'name' => 'Governance Co',
            'slug' => 'governance-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
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
            'provider_customer_id' => 'cus_test_governance',
        ]);

        // Finalized invoice (2900 cents = 29.00)
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $this->invoice = InvoiceIssuer::finalize($draft);

        config(['billing.alerting.enabled' => false]);
        config(['billing.writeoff_threshold' => 0]);

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

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = []): \Stripe\PaymentIntent
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
    // A) Period Closing (8 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_financial_period_can_be_created(): void
    {
        $period = FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => false,
        ]);

        $this->assertNotNull($period->id);
        $this->assertFalse($period->is_closed);
    }

    public function test_financial_period_can_be_closed(): void
    {
        $period = FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->assertTrue($period->is_closed);
        $this->assertNotNull($period->closed_at);
    }

    public function test_closed_period_casts_dates(): void
    {
        $period = FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $period->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $period->start_date);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $period->end_date);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $period->closed_at);
    }

    public function test_period_unique_constraint(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => false,
        ]);
    }

    public function test_different_companies_can_have_same_period(): void
    {
        $company2 = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $period2 = FinancialPeriod::create([
            'company_id' => $company2->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->assertNotNull($period2->id);
    }

    public function test_audit_action_constants_exist(): void
    {
        $this->assertEquals('billing.period_closed', AuditAction::BILLING_PERIOD_CLOSED);
        $this->assertEquals('billing.financial_freeze_enabled', AuditAction::BILLING_FINANCIAL_FREEZE_ENABLED);
        $this->assertEquals('billing.financial_freeze_disabled', AuditAction::BILLING_FINANCIAL_FREEZE_DISABLED);
    }

    public function test_company_has_financial_freeze_attribute(): void
    {
        $this->company->refresh();
        $this->assertFalse($this->company->financial_freeze);

        $this->company->update(['financial_freeze' => true]);
        $this->company->refresh();

        $this->assertTrue($this->company->financial_freeze);
    }

    public function test_financial_freeze_defaults_to_false(): void
    {
        $newCompany = Company::create([
            'name' => 'New Co',
            'slug' => 'new-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        $newCompany->refresh(); // Get DB defaults
        $this->assertFalse($newCompany->financial_freeze);
    }

    // ═══════════════════════════════════════════════════════════
    // B) Ledger Guard — Closed Period (7 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_assert_period_open_passes_when_no_period(): void
    {
        // No closed periods — should not throw
        LedgerService::assertPeriodOpen($this->company->id);
        $this->assertTrue(true);
    }

    public function test_assert_period_open_passes_when_period_is_open(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => now()->subDays(30)->format('Y-m-d'),
            'end_date' => now()->addDays(30)->format('Y-m-d'),
            'is_closed' => false, // Not closed!
        ]);

        LedgerService::assertPeriodOpen($this->company->id);
        $this->assertTrue(true);
    }

    public function test_assert_period_open_throws_when_date_in_closed_period(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => now()->subDays(30)->format('Y-m-d'),
            'end_date' => now()->addDays(30)->format('Y-m-d'),
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed financial period');

        LedgerService::assertPeriodOpen($this->company->id);
    }

    public function test_assert_period_open_passes_when_date_outside_closed_period(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        // Today is not in 2025-01 — should pass
        LedgerService::assertPeriodOpen($this->company->id);
        $this->assertTrue(true);
    }

    public function test_record_invoice_rejected_in_closed_period(): void
    {
        // Close the current period
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->addDays(1)->format('Y-m-d'),
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Blocked plan', 1000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed financial period');

        LedgerService::recordInvoiceIssued($draft);
    }

    public function test_record_payment_rejected_in_closed_period(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->addDays(1)->format('Y-m-d'),
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $payment = Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_blocked',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed financial period');

        LedgerService::recordPaymentReceived($payment);
    }

    public function test_record_writeoff_rejected_in_closed_period(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => now()->subDays(1)->format('Y-m-d'),
            'end_date' => now()->addDays(1)->format('Y-m-d'),
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed financial period');

        LedgerService::recordWriteOff($this->invoice);
    }

    // ═══════════════════════════════════════════════════════════
    // C) Adjustment Entries (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_adjustment_creates_two_balanced_entries(): void
    {
        $correlationId = LedgerService::recordAdjustment(
            $this->company->id,
            'AR',
            'REVENUE',
            10.00,
            'EUR',
            'invoice',
            $this->invoice->id,
            'Correction for overcharge',
        );

        $entries = LedgerEntry::where('correlation_id', $correlationId)->get();

        $this->assertCount(2, $entries);
        $this->assertEquals($entries->sum('debit'), $entries->sum('credit'));
    }

    public function test_adjustment_has_entry_type_adjustment(): void
    {
        $correlationId = LedgerService::recordAdjustment(
            $this->company->id,
            'AR',
            'REVENUE',
            5.00,
            'EUR',
            'invoice',
            $this->invoice->id,
            'Minor fix',
        );

        $entries = LedgerEntry::where('correlation_id', $correlationId)->get();

        foreach ($entries as $entry) {
            $this->assertEquals('adjustment', $entry->entry_type);
        }
    }

    public function test_adjustment_stores_reason_in_metadata(): void
    {
        $correlationId = LedgerService::recordAdjustment(
            $this->company->id,
            'AR',
            'REVENUE',
            5.00,
            'EUR',
            'invoice',
            $this->invoice->id,
            'Tax recalculation',
        );

        $entry = LedgerEntry::where('correlation_id', $correlationId)->first();
        $metadata = json_decode($entry->metadata, true);

        $this->assertEquals('Tax recalculation', $metadata['reason']);
    }

    public function test_adjustment_returns_correlation_id(): void
    {
        $correlationId = LedgerService::recordAdjustment(
            $this->company->id,
            'AR',
            'REVENUE',
            5.00,
            'EUR',
            'invoice',
            $this->invoice->id,
            'Test reason',
        );

        $this->assertNotEmpty($correlationId);
        $this->assertCount(2, LedgerEntry::where('correlation_id', $correlationId)->get());
    }

    public function test_adjustment_rejects_zero_amount(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive');

        LedgerService::recordAdjustment(
            $this->company->id,
            'AR',
            'REVENUE',
            0,
            'EUR',
            'invoice',
            $this->invoice->id,
            'Should fail',
        );
    }

    // ═══════════════════════════════════════════════════════════
    // D) Financial Freeze (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_frozen_company_blocks_ledger_write(): void
    {
        $this->company->update(['financial_freeze' => true]);

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Frozen plan', 1000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('financially frozen');

        LedgerService::recordInvoiceIssued($draft);
    }

    public function test_frozen_company_blocks_adjustment(): void
    {
        $this->company->update(['financial_freeze' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('financially frozen');

        LedgerService::recordAdjustment(
            $this->company->id,
            'AR',
            'REVENUE',
            5.00,
            'EUR',
            'invoice',
            $this->invoice->id,
            'Should fail',
        );
    }

    public function test_frozen_company_blocks_writeoff_via_admin(): void
    {
        $this->company->update(['financial_freeze' => true]);
        $this->invoice->update(['status' => 'overdue']);

        $service = app(AdminAdvancedMutationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('financially frozen');

        $service->writeOff($this->invoice, 'idem-freeze-writeoff');
    }

    public function test_frozen_company_blocks_refund_via_admin(): void
    {
        $this->company->update(['financial_freeze' => true]);
        $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);

        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_freeze_refund',
        ]);

        $service = app(AdminAdvancedMutationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('financially frozen');

        $service->refund($this->invoice, 500, 'Blocked refund', 'idem-freeze-refund');
    }

    public function test_unfrozen_company_allows_ledger_write(): void
    {
        $this->company->update(['financial_freeze' => false]);

        $payment = Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_unfrozen',
        ]);

        LedgerService::recordPaymentReceived($payment);

        $entries = LedgerEntry::where('entry_type', 'payment_received')
            ->where('reference_id', $payment->id)
            ->get();

        $this->assertCount(2, $entries);
    }

    // ═══════════════════════════════════════════════════════════
    // E) Writeoff Threshold (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_writeoff_below_threshold_succeeds(): void
    {
        config(['billing.writeoff_threshold' => 5000]); // 50.00
        $this->invoice->update(['status' => 'overdue']); // amount_due = 2900

        $service = app(AdminAdvancedMutationService::class);
        $result = $service->writeOff($this->invoice, 'idem-threshold-ok');

        $this->assertFalse($result['replayed']);
        $this->assertEquals('uncollectible', $result['invoice']->status);
    }

    public function test_writeoff_above_threshold_blocked(): void
    {
        config(['billing.writeoff_threshold' => 1000]); // 10.00 — invoice is 29.00
        $this->invoice->update(['status' => 'overdue']);

        $service = app(AdminAdvancedMutationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds threshold');

        $service->writeOff($this->invoice, 'idem-threshold-block');
    }

    public function test_writeoff_equal_to_threshold_succeeds(): void
    {
        config(['billing.writeoff_threshold' => 2900]); // Exactly the invoice amount
        $this->invoice->update(['status' => 'overdue']);

        $service = app(AdminAdvancedMutationService::class);
        $result = $service->writeOff($this->invoice, 'idem-threshold-exact');

        $this->assertFalse($result['replayed']);
    }

    public function test_writeoff_threshold_zero_means_unlimited(): void
    {
        config(['billing.writeoff_threshold' => 0]); // 0 = no limit

        // Create a large invoice
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Enterprise', 999900); // 9999.00
        $bigInvoice = InvoiceIssuer::finalize($draft);
        $bigInvoice->update(['status' => 'overdue']);

        $service = app(AdminAdvancedMutationService::class);
        $result = $service->writeOff($bigInvoice, 'idem-no-limit');

        $this->assertFalse($result['replayed']);
    }

    public function test_writeoff_threshold_from_config(): void
    {
        config(['billing.writeoff_threshold' => 5000]);
        $this->assertEquals(5000, (int) config('billing.writeoff_threshold'));

        config(['billing.writeoff_threshold' => 0]);
        $this->assertEquals(0, (int) config('billing.writeoff_threshold'));
    }

    // ═══════════════════════════════════════════════════════════
    // F) Period Close Command (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_period_close_command_dry_run(): void
    {
        $this->artisan('billing:period-close', [
            'company' => $this->company->id,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        // No period created
        $this->assertEquals(0, FinancialPeriod::count());
    }

    public function test_period_close_command_creates_closed_period(): void
    {
        $this->artisan('billing:period-close', [
            'company' => $this->company->id,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ])
            ->expectsOutputToContain('closed successfully')
            ->assertExitCode(0);

        $period = FinancialPeriod::where('company_id', $this->company->id)->first();
        $this->assertNotNull($period);
        $this->assertTrue($period->is_closed);
        $this->assertEquals('2026-01-01', $period->start_date->format('Y-m-d'));
        $this->assertEquals('2026-01-31', $period->end_date->format('Y-m-d'));
    }

    public function test_period_close_command_logs_audit(): void
    {
        $this->artisan('billing:period-close', [
            'company' => $this->company->id,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ])->assertExitCode(0);

        $log = PlatformAuditLog::where('action', AuditAction::BILLING_PERIOD_CLOSED)->first();
        $this->assertNotNull($log);
        $this->assertEquals('company', $log->target_type);
        $this->assertEquals((string) $this->company->id, $log->target_id);
        $this->assertEquals('critical', $log->severity);
    }

    public function test_period_close_rejects_overlap(): void
    {
        FinancialPeriod::create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        $this->artisan('billing:period-close', [
            'company' => $this->company->id,
            'start' => '2026-01-15',
            'end' => '2026-02-15',
        ])
            ->expectsOutputToContain('Overlapping')
            ->assertExitCode(1);
    }

    public function test_period_close_rejects_invalid_dates(): void
    {
        $this->artisan('billing:period-close', [
            'company' => $this->company->id,
            'start' => '2026-02-28',
            'end' => '2026-02-01', // End before start
        ])
            ->expectsOutputToContain('before or equal')
            ->assertExitCode(1);
    }
}
