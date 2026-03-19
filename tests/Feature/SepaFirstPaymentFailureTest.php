<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\ScheduledDebit;
use App\Core\Billing\ScheduledDebitService;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ADR-325/328: SEPA first payment failure behavior + debit protocol tests.
 */
class SepaFirstPaymentFailureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

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

        Market::firstOrCreate(
            ['key' => 'FR'],
            ['name' => 'France', 'currency' => 'EUR', 'vat_rate_bps' => 2000, 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris', 'dial_code' => '+33'],
        );

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'SEPA Failure Co',
            'slug' => 'sepa-failure-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        WalletLedger::ensureWallet($this->company);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
        ]);

        // Default payment method: SEPA
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
            'metadata' => ['bank_name' => 'Test Bank'],
        ]);
    }

    public function test_sepa_first_failure_suspends_when_policy_suspend(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_first_failure_action' => 'suspend',
            'grace_period_days' => 0,
        ]);
        PlatformBillingPolicy::clearCache();

        // Create a first invoice (no prior paid invoices for this subscription)
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'number' => 'INV-SEPA-001',
            'status' => 'open',
            'amount_due' => 2900,
            'amount_paid' => 0,
            'currency' => 'EUR',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'issued_at' => now()->subDays(2),
            'due_at' => now()->subDay(),
            'finalized_at' => now()->subDays(2),
        ]);

        $stats = DunningEngine::processOverdueInvoices();

        // Should have been handled as SEPA first failure
        $this->assertGreaterThan(0, $stats['exhausted']);

        // Invoice should be uncollectible
        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);

        // Company should be suspended
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);
    }

    public function test_sepa_first_failure_uses_dunning_when_policy_dunning(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_first_failure_action' => 'dunning',
            'grace_period_days' => 0,
        ]);
        PlatformBillingPolicy::clearCache();

        // Create a first invoice
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'number' => 'INV-SEPA-002',
            'status' => 'open',
            'amount_due' => 2900,
            'amount_paid' => 0,
            'currency' => 'EUR',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'issued_at' => now()->subDays(2),
            'due_at' => now()->subDay(),
            'finalized_at' => now()->subDays(2),
        ]);

        $stats = DunningEngine::processOverdueInvoices();

        // Should have been processed normally (not as SEPA first failure)
        $this->assertEquals(0, $stats['exhausted']);
        $this->assertGreaterThan(0, $stats['processed']);

        // Invoice should be overdue (standard dunning)
        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);

        // Company should still be active (not suspended yet)
        $this->company->refresh();
        $this->assertNotEquals('suspended', $this->company->status);
    }

    // ── ADR-328: Scheduled Debit Protocol (+8 tests) ────

    private function createOpenInvoice(string $number = 'INV-SEPA-100', int $amount = 2900): Invoice
    {
        return Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'number' => $number,
            'status' => 'open',
            'amount_due' => $amount,
            'amount_paid' => 0,
            'currency' => 'EUR',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'issued_at' => now(),
            'due_at' => now()->addDays(14),
            'finalized_at' => now(),
        ]);
    }

    public function test_scheduled_debit_created_for_sepa_with_preferred_day(): void
    {
        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)->first();
        $profile->update(['preferred_debit_day' => 15]);

        $invoice = $this->createOpenInvoice('INV-SEPA-SD1');

        $debit = ScheduledDebitService::maybeSchedule($invoice);

        $this->assertNotNull($debit);
        $this->assertEquals('pending', $debit->status);
        $this->assertEquals($invoice->id, $debit->invoice_id);
        $this->assertEquals($this->company->id, $debit->company_id);
        $this->assertEquals(2900, $debit->amount);
    }

    public function test_no_scheduled_debit_for_card_profile(): void
    {
        // Switch to card payment method
        CompanyPaymentProfile::where('company_id', $this->company->id)->update([
            'method_key' => 'card',
            'preferred_debit_day' => 15,
        ]);

        $invoice = $this->createOpenInvoice('INV-SEPA-SD2');
        $debit = ScheduledDebitService::maybeSchedule($invoice);

        $this->assertNull($debit);
    }

    public function test_no_scheduled_debit_without_preferred_day(): void
    {
        // SEPA profile but no preferred_debit_day
        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)->first();
        $profile->update(['preferred_debit_day' => null]);

        $invoice = $this->createOpenInvoice('INV-SEPA-SD3');
        $debit = ScheduledDebitService::maybeSchedule($invoice);

        $this->assertNull($debit);
    }

    public function test_collect_scheduled_skips_paid_invoice(): void
    {
        $invoice = $this->createOpenInvoice('INV-SEPA-SD4');
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)->first();

        ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'payment_profile_id' => $profile->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->subDay(),
            'status' => 'pending',
        ]);

        Artisan::call('billing:collect-scheduled');

        $debit = ScheduledDebit::where('invoice_id', $invoice->id)->first();
        $this->assertEquals('cancelled', $debit->status);
    }

    public function test_collect_scheduled_skips_void_invoice(): void
    {
        $invoice = $this->createOpenInvoice('INV-SEPA-SD5');
        $invoice->update(['status' => 'void', 'voided_at' => now()]);

        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)->first();

        ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'payment_profile_id' => $profile->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->subDay(),
            'status' => 'pending',
        ]);

        Artisan::call('billing:collect-scheduled');

        $debit = ScheduledDebit::where('invoice_id', $invoice->id)->first();
        $this->assertEquals('cancelled', $debit->status);
    }

    public function test_collect_scheduled_skips_future_debits(): void
    {
        $invoice = $this->createOpenInvoice('INV-SEPA-SD6');
        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)->first();

        ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'payment_profile_id' => $profile->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->addDays(10),
            'status' => 'pending',
        ]);

        Artisan::call('billing:collect-scheduled');

        $debit = ScheduledDebit::where('invoice_id', $invoice->id)->first();
        $this->assertEquals('pending', $debit->status);
        $this->assertNull($debit->processed_at);
    }

    public function test_compute_debit_date_february_overflow(): void
    {
        // Set test date to Feb 1
        Carbon::setTestNow(Carbon::create(2026, 2, 1));

        $date = ScheduledDebitService::computeNextDebitDate(31);

        // Feb has 28 days in 2026 → should clamp to Feb 28
        $this->assertEquals(28, $date->day);
        $this->assertEquals(2, $date->month);

        Carbon::setTestNow();
    }

    public function test_compute_debit_date_today_schedules_next_month(): void
    {
        // Set test date to March 15
        Carbon::setTestNow(Carbon::create(2026, 3, 15));

        $date = ScheduledDebitService::computeNextDebitDate(15);

        // preferred_day == today → schedule next month
        $this->assertEquals(15, $date->day);
        $this->assertEquals(4, $date->month);

        Carbon::setTestNow();
    }

    public function test_sepa_first_failure_only_applies_to_first_invoice(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_first_failure_action' => 'suspend',
            'grace_period_days' => 0,
        ]);
        PlatformBillingPolicy::clearCache();

        // Create a PRIOR paid invoice (this is NOT the first payment)
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_prior_sepa',
        ]);

        Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'number' => 'INV-SEPA-PRIOR',
            'status' => 'paid',
            'amount_due' => 0,
            'amount_paid' => 2900,
            'currency' => 'EUR',
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonth(),
            'issued_at' => now()->subMonths(2),
            'due_at' => now()->subMonths(2)->addDays(14),
            'finalized_at' => now()->subMonths(2),
            'paid_at' => now()->subMonths(2),
        ]);

        // Now the SECOND invoice fails
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'number' => 'INV-SEPA-SECOND',
            'status' => 'open',
            'amount_due' => 2900,
            'amount_paid' => 0,
            'currency' => 'EUR',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'issued_at' => now()->subDays(2),
            'due_at' => now()->subDay(),
            'finalized_at' => now()->subDays(2),
        ]);

        $stats = DunningEngine::processOverdueInvoices();

        // Should NOT be treated as first failure — normal dunning applies
        $invoice->refresh();
        $this->company->refresh();

        // Company should NOT be immediately suspended (it's a retry, not first failure)
        $this->assertNotEquals('suspended', $this->company->status);
        $this->assertEquals('overdue', $invoice->status);
    }
}
