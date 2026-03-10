<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-325: SEPA first payment failure behavior tests.
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
}
