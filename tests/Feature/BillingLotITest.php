<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Invoice;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\ScheduledDebit;
use App\Core\Billing\ScheduledDebitService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-328 LOT I: SEPA hardening.
 *
 * S2: Scheduled SEPA debits (ScheduledDebit + ScheduledDebitService + command)
 * S3: Debit day per payment profile (API endpoint)
 * S4: Explicit SEPA mandate guard
 * S8: Platform admin scheduled-debits endpoint
 */
class BillingLotITest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Subscription $subscription;

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

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotI Co',
            'slug' => 'loti-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);
    }

    private function actAs(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ── S2: Scheduled SEPA debits ──────────────────────────────

    public function test_scheduled_debit_created_for_sepa_with_preferred_day(): void
    {
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
            'preferred_debit_day' => 15,
            'metadata' => ['mandate_accepted_at' => now()->toISOString()],
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        $result = ScheduledDebitService::maybeSchedule($invoice);

        $this->assertNotNull($result);
        $this->assertInstanceOf(ScheduledDebit::class, $result);
        $this->assertEquals('pending', $result->status);
        $this->assertEquals(2900, $result->amount);
        $this->assertEquals($this->company->id, $result->company_id);
        $this->assertEquals($invoice->id, $result->invoice_id);
    }

    public function test_no_scheduled_debit_for_card_or_no_preferred_day(): void
    {
        // Card method → no scheduling
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'is_default' => true,
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        $this->assertNull(ScheduledDebitService::maybeSchedule($invoice));

        // SEPA without preferred_debit_day → no scheduling
        CompanyPaymentProfile::where('company_id', $this->company->id)->delete();
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
            'preferred_debit_day' => null,
        ]);

        $this->assertNull(ScheduledDebitService::maybeSchedule($invoice));
    }

    public function test_collect_scheduled_processes_due_debits(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        $profile = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
            'preferred_debit_day' => 10,
            'metadata' => ['mandate_accepted_at' => now()->toISOString()],
        ]);

        $debit = ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'payment_profile_id' => $profile->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->subDay(),
            'status' => 'pending',
        ]);

        // Mock the PaymentGatewayManager to return a mock adapter
        $mockAdapter = new class extends StripePaymentAdapter
        {
            public function collectInvoice(Invoice $invoice, $company, array $metadata = []): array
            {
                return ['status' => 'succeeded', 'payment_intent_id' => 'pi_mock_test'];
            }
        };

        $mockManager = \Mockery::mock(PaymentGatewayManager::class);
        $mockManager->shouldReceive('driver')->andReturn($mockAdapter);
        $this->app->instance(PaymentGatewayManager::class, $mockManager);

        $this->artisan('billing:collect-scheduled')
            ->assertSuccessful();

        $debit->refresh();
        $this->assertEquals('collected', $debit->status);
        $this->assertNotNull($debit->processed_at);
    }

    public function test_collect_scheduled_skips_future_debits(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        $debit = ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->addDays(10),
            'status' => 'pending',
        ]);

        $this->artisan('billing:collect-scheduled')
            ->assertSuccessful();

        $debit->refresh();
        $this->assertEquals('pending', $debit->status);
    }

    public function test_collect_scheduled_failed_marks_debit_failed(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        $profile = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
            'preferred_debit_day' => 10,
            'metadata' => ['mandate_accepted_at' => now()->toISOString()],
        ]);

        $debit = ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'payment_profile_id' => $profile->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->subDay(),
            'status' => 'pending',
        ]);

        // Mock the PaymentGatewayManager to return a failing adapter
        $mockAdapter = new class extends StripePaymentAdapter
        {
            public function collectInvoice(Invoice $invoice, $company, array $metadata = []): array
            {
                return ['status' => 'failed', 'failure_reason' => 'insufficient_funds'];
            }
        };

        $mockManager = \Mockery::mock(PaymentGatewayManager::class);
        $mockManager->shouldReceive('driver')->andReturn($mockAdapter);
        $this->app->instance(PaymentGatewayManager::class, $mockManager);

        $this->artisan('billing:collect-scheduled')
            ->assertSuccessful();

        $debit->refresh();
        $this->assertEquals('failed', $debit->status);
        $this->assertNotNull($debit->processed_at);
        $this->assertEquals('insufficient_funds', $debit->failure_reason);
    }

    // ── S3: Set debit day per profile ──────────────────────────

    public function test_set_debit_day_on_sepa_profile(): void
    {
        $profile = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
        ]);

        $response = $this->actAs()
            ->putJson("/api/billing/saved-cards/{$profile->id}/debit-day", [
                'preferred_debit_day' => 15,
            ]);

        $response->assertOk();
        $profile->refresh();
        $this->assertEquals(15, $profile->preferred_debit_day);
    }

    public function test_set_debit_day_rejected_for_card_profile(): void
    {
        $profile = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'is_default' => true,
        ]);

        $response = $this->actAs()
            ->putJson("/api/billing/saved-cards/{$profile->id}/debit-day", [
                'preferred_debit_day' => 15,
            ]);

        $response->assertStatus(422);
    }

    // ── S4: SEPA mandate guard ─────────────────────────────────

    public function test_sepa_payment_blocked_without_mandate(): void
    {
        // SEPA profile without mandate_accepted_at
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'is_default' => true,
            'metadata' => [],
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        // The StripePaymentAdapter.collectInvoice checks mandate before calling Stripe
        $adapter = new StripePaymentAdapter();
        $result = $adapter->collectInvoice($invoice, $this->company);

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('mandate', $result['raw_response']['error'] ?? '');
    }

    public function test_card_payment_unaffected_by_mandate_check(): void
    {
        // Card profile → mandate check should not apply
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'is_default' => true,
        ]);

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        // The StripePaymentAdapter will throw because there's no Stripe customer, but it
        // should NOT fail due to mandate check. We verify by checking it doesn't short-circuit.
        $adapter = new StripePaymentAdapter();

        try {
            $result = $adapter->collectInvoice($invoice, $this->company);
            $this->assertNotEquals('SEPA mandate not accepted.', $result['raw_response']['error'] ?? '');
        } catch (\Throwable $e) {
            // Expected: Stripe API error or missing customer — not a mandate error
            $this->assertStringNotContainsString('mandate', $e->getMessage());
        }
    }

    // ── S8: Platform scheduled-debits endpoint ─────────────────

    public function test_platform_scheduled_debits_endpoint(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
            'number' => 'INV-2026-000001',
        ]);

        ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->addDays(5),
            'status' => 'pending',
        ]);

        $admin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Billing',
            'email' => 'lot-i-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/billing/scheduled-debits');

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.company_id', $this->company->id);
        $response->assertJsonPath('data.0.amount', 2900);
    }

    public function test_platform_scheduled_debits_filters(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'status' => 'open',
            'currency' => 'EUR',
            'amount_due' => 2900,
        ]);

        ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'debit_date' => now()->addDays(5),
            'status' => 'pending',
        ]);

        ScheduledDebit::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'amount' => 1500,
            'currency' => 'EUR',
            'debit_date' => now()->addDays(10),
            'status' => 'collected',
        ]);

        $admin = PlatformUser::create([
            'first_name' => 'Filter',
            'last_name' => 'Admin',
            'email' => 'lot-i-filter@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        // Filter by status=pending → should return 1
        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/billing/scheduled-debits?status=pending');

        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.status', 'pending');
    }

    // ── Compute next debit date ────────────────────────────────

    public function test_compute_next_debit_date_future_day(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(4)); // 5th of month

        $date = ScheduledDebitService::computeNextDebitDate(25);

        $this->assertEquals(25, $date->day);
        $this->travelBack();
    }

    public function test_compute_next_debit_date_past_day(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(19)); // 20th of month

        $date = ScheduledDebitService::computeNextDebitDate(5);

        // Day 5 has passed → next month day 5
        $this->assertEquals(5, $date->day);
        $this->assertTrue($date->isAfter(now()));

        $this->travelBack();
    }
}
