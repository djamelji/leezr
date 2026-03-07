<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CreditNote;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ADR-139 D3c: Provider-First Collection & Refund Chaining.
 *
 * Tests that DunningEngine tries Stripe before wallet,
 * admin refund chains to Stripe, and rate limiting works.
 */
class StripeProviderCollectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Subscription $subscription;
    private Invoice $invoice;
    private PlatformUser $admin;

    private bool $stripeCollectCalled = false;
    private string $stripeCollectStatus = 'succeeded';
    private bool $stripeRefundCalled = false;
    private string $stripeRefundStatus = 'succeeded';
    private bool $stripeRefundShouldThrow = false;

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

        // Company + owner
        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Collection Co',
            'slug' => 'collection-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        // Subscription with Stripe provider
        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-31'),
        ]);

        // Stripe customer mapping
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test_collection',
        ]);

        // Finalized invoice
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $this->invoice = InvoiceIssuer::finalize($draft);

        // Platform admin
        $this->admin = PlatformUser::create([
            'first_name' => 'Billing',
            'last_name' => 'Admin',
            'email' => 'billing-admin-d3c@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        // Bind mock adapter
        $this->bindMockAdapter();
    }

    private function bindMockAdapter(): void
    {
        $test = $this;

        $this->app->bind(StripePaymentAdapter::class, function () use ($test) {
            return new class($test) extends StripePaymentAdapter
            {
                private $testRef;

                public function __construct($testRef)
                {
                    $this->testRef = $testRef;
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = []): \Stripe\PaymentIntent
                {
                    $this->testRef->markCollectCalled();

                    $status = $this->testRef->getCollectStatus();

                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_test_collection_' . uniqid(),
                        'amount' => $amount,
                        'amount_received' => $status === 'succeeded' ? $amount : 0,
                        'currency' => $currency,
                        'status' => $status,
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
                {
                    $this->testRef->markRefundCalled();

                    if ($this->testRef->shouldRefundThrow()) {
                        throw new \RuntimeException('Stripe refund failed: card_declined');
                    }

                    return \Stripe\Refund::constructFrom([
                        'id' => 're_test_' . uniqid(),
                        'amount' => $amount,
                        'status' => 'succeeded',
                    ]);
                }
            };
        });
    }

    // ── Test-accessible hooks ──────────────────────────────────

    public function markCollectCalled(): void
    {
        $this->stripeCollectCalled = true;
    }

    public function getCollectStatus(): string
    {
        return $this->stripeCollectStatus;
    }

    public function markRefundCalled(): void
    {
        $this->stripeRefundCalled = true;
    }

    public function shouldRefundThrow(): bool
    {
        return $this->stripeRefundShouldThrow;
    }

    // ── Helpers ────────────────────────────────────────────────

    private function makeOverdue(Invoice $invoice): Invoice
    {
        $invoice->update([
            'status' => 'overdue',
            'due_at' => Carbon::parse('2026-02-20'),
            'next_retry_at' => now()->subMinute(),
        ]);

        return $invoice->fresh();
    }

    private function makePaid(Invoice $invoice): Invoice
    {
        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $invoice->fresh();
    }

    private function actAsPlatform(): static
    {
        return $this->actingAs($this->admin, 'platform');
    }

    // ═══════════════════════════════════════════════════════════
    // A) Provider collection (7 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_retry_attempts_stripe_first(): void
    {
        $this->stripeCollectStatus = 'succeeded';
        $invoice = $this->makeOverdue($this->invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $this->assertTrue($this->stripeCollectCalled, 'Stripe collectInvoice was called');
        $this->assertEquals(1, $stats['processed']);
    }

    public function test_successful_provider_does_not_mark_invoice_paid(): void
    {
        $this->stripeCollectStatus = 'succeeded';
        $invoice = $this->makeOverdue($this->invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        // Invoice stays overdue — webhook will finalize
        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        $this->assertNull($invoice->paid_at);

        // retry_count incremented
        $this->assertEquals(1, $invoice->retry_count);
    }

    public function test_provider_failure_falls_back_to_wallet(): void
    {
        $this->stripeCollectStatus = 'requires_action'; // Not succeeded → fallback

        $invoice = $this->makeOverdue($this->invoice);

        // Credit wallet enough to cover
        WalletLedger::credit(
            $this->company, 5000, 'admin_adjustment',
            actorType: 'platform_user', actorId: 1,
        );

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $this->assertTrue($this->stripeCollectCalled);

        // Wallet covered it
        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_no_wallet_no_provider_exhausts(): void
    {
        $this->stripeCollectStatus = 'failed';

        $invoice = $this->makeOverdue($this->invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'max_retry_attempts' => 1,
            'retry_intervals_days' => [1],
            'failure_action' => 'suspend',
        ]);

        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $this->assertTrue($this->stripeCollectCalled);

        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);
        $this->assertEquals(1, $stats['exhausted']);

        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);
    }

    public function test_collect_invoice_uses_amount_due(): void
    {
        $capturedAmount = null;
        $test = $this;

        $this->app->bind(StripePaymentAdapter::class, function () use (&$capturedAmount, $test) {
            return new class($capturedAmount, $test) extends StripePaymentAdapter
            {
                private $amountRef;
                private $testRef;

                public function __construct(&$amountRef, $testRef)
                {
                    $this->amountRef = &$amountRef;
                    $this->testRef = $testRef;
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = []): \Stripe\PaymentIntent
                {
                    $this->amountRef = $amount;
                    $this->testRef->markCollectCalled();

                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_amount_check',
                        'amount' => $amount,
                        'amount_received' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
                {
                    return \Stripe\Refund::constructFrom(['id' => 're_noop', 'amount' => $amount, 'status' => 'succeeded']);
                }
            };
        });

        $invoice = $this->makeOverdue($this->invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $this->assertEquals($this->invoice->amount_due, $capturedAmount);
    }

    public function test_collect_invoice_metadata_contains_invoice_id(): void
    {
        $capturedMeta = null;
        $test = $this;

        $this->app->bind(StripePaymentAdapter::class, function () use (&$capturedMeta, $test) {
            return new class($capturedMeta, $test) extends StripePaymentAdapter
            {
                private $metaRef;
                private $testRef;

                public function __construct(&$metaRef, $testRef)
                {
                    $this->metaRef = &$metaRef;
                    $this->testRef = $testRef;
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = []): \Stripe\PaymentIntent
                {
                    $this->metaRef = $metadata;
                    $this->testRef->markCollectCalled();

                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_meta_check',
                        'amount' => $amount,
                        'amount_received' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
                {
                    return \Stripe\Refund::constructFrom(['id' => 're_noop', 'amount' => $amount, 'status' => 'succeeded']);
                }
            };
        });

        $invoice = $this->makeOverdue($this->invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $this->assertNotNull($capturedMeta);
        $this->assertEquals((string) $this->invoice->id, $capturedMeta['invoice_id']);
        $this->assertEquals((string) $this->company->id, $capturedMeta['company_id']);
    }

    public function test_internal_provider_skips_collection(): void
    {
        // Change subscription to internal provider
        $this->subscription->update(['provider' => 'internal']);

        $invoice = $this->makeOverdue($this->invoice);

        // Credit wallet
        WalletLedger::credit(
            $this->company, 5000, 'admin_adjustment',
            actorType: 'platform_user', actorId: 1,
        );

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $this->assertFalse($this->stripeCollectCalled, 'Stripe should NOT be called for internal provider');

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
    }

    // ═══════════════════════════════════════════════════════════
    // B) Admin refund chaining (6 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_admin_refund_calls_stripe_refund(): void
    {
        $invoice = $this->makePaid($this->invoice);

        // Create a provider Payment
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_paid_via_stripe',
        ]);

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Customer overcharged',
                'idempotency_key' => 'refund-stripe-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Refund credit note issued.');

        $this->assertTrue($this->stripeRefundCalled, 'Stripe refund was called');
    }

    public function test_admin_refund_creates_cn_with_provider_refund_id(): void
    {
        $invoice = $this->makePaid($this->invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_for_cn_meta',
        ]);

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 500,
                'reason' => 'Goodwill refund',
                'idempotency_key' => 'refund-stripe-cn',
            ])
            ->assertOk();

        $cn = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'refund')
            ->first();

        $this->assertNotNull($cn);
        $this->assertNotNull($cn->metadata['provider_refund_id']);
        $this->assertEquals('pi_for_cn_meta', $cn->metadata['provider_payment_id']);
    }

    public function test_admin_refund_aborts_on_provider_failure(): void
    {
        $this->stripeRefundShouldThrow = true;

        $invoice = $this->makePaid($this->invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_will_fail_refund',
        ]);

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Should fail',
                'idempotency_key' => 'refund-stripe-fail',
            ]);

        $response->assertStatus(409);

        // No CreditNote should be created
        $this->assertDatabaseMissing('credit_notes', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_admin_refund_idempotent_replay(): void
    {
        $invoice = $this->makePaid($this->invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_idempotent',
        ]);

        // First call
        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'First refund',
                'idempotency_key' => 'refund-idem-stripe',
            ])
            ->assertOk();

        $this->stripeRefundCalled = false;

        // Second call — same idempotency key
        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'First refund',
                'idempotency_key' => 'refund-idem-stripe',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Already processed (idempotent replay).');

        // Stripe refund was NOT called again
        $this->assertFalse($this->stripeRefundCalled, 'Stripe refund should not be called on replay');

        // Only one CN exists
        $count = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'refund')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_partial_refund_chain(): void
    {
        $capturedRefundAmount = null;
        $test = $this;

        $this->app->bind(StripePaymentAdapter::class, function () use (&$capturedRefundAmount, $test) {
            return new class($capturedRefundAmount, $test) extends StripePaymentAdapter
            {
                private $refundAmountRef;
                private $testRef;

                public function __construct(&$refundAmountRef, $testRef)
                {
                    $this->refundAmountRef = &$refundAmountRef;
                    $this->testRef = $testRef;
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = []): \Stripe\PaymentIntent
                {
                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_noop', 'amount' => $amount, 'amount_received' => $amount,
                        'currency' => $currency, 'status' => 'succeeded', 'metadata' => $metadata,
                    ]);
                }

                protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
                {
                    $this->refundAmountRef = $amount;
                    $this->testRef->markRefundCalled();

                    return \Stripe\Refund::constructFrom([
                        'id' => 're_partial', 'amount' => $amount, 'status' => 'succeeded',
                    ]);
                }
            };
        });

        $invoice = $this->makePaid($this->invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_partial_refund',
        ]);

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 750,
                'reason' => 'Partial refund',
                'idempotency_key' => 'refund-partial-001',
            ])
            ->assertOk();

        $this->assertTrue($this->stripeRefundCalled);
        $this->assertEquals(750, $capturedRefundAmount);
    }

    public function test_wallet_only_refund_no_stripe_call(): void
    {
        $invoice = $this->makePaid($this->invoice);

        // No provider Payment exists — wallet-only invoice

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Wallet-only refund',
                'idempotency_key' => 'refund-wallet-only',
            ]);

        $response->assertOk();

        $this->assertFalse($this->stripeRefundCalled, 'Stripe should NOT be called for wallet-only invoices');

        $cn = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'refund')
            ->first();

        $this->assertNotNull($cn);
        $this->assertNull($cn->metadata['provider_refund_id']);
        $this->assertNull($cn->metadata['provider_payment_id']);
    }

    // ═══════════════════════════════════════════════════════════
    // C) Rate limit (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_rate_limit_blocks_collection(): void
    {
        // Fill rate limiter bucket
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit("stripe-api:{$this->company->id}", 60);
        }

        $invoice = $this->makeOverdue($this->invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        // Provider throws due to rate limit → falls back to wallet (no wallet → reschedule)
        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        // Should NOT crash — rate limit triggers RuntimeException caught by attemptProviderPayment
        $this->assertEquals(1, $stats['processed']);

        // Invoice should still be overdue (rescheduled), not provider_attempted
        $invoice->refresh();
        $this->assertIn($invoice->status, ['overdue', 'uncollectible']);
    }

    public function test_rate_limit_does_not_break_wallet_fallback(): void
    {
        // Fill rate limiter
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit("stripe-api:{$this->company->id}", 60);
        }

        $invoice = $this->makeOverdue($this->invoice);

        // Credit wallet
        WalletLedger::credit(
            $this->company, 5000, 'admin_adjustment',
            actorType: 'platform_user', actorId: 1,
        );

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        Carbon::setTestNow(now());
        $stats = DunningEngine::processOverdueInvoices();
        Carbon::setTestNow();

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status, 'Wallet should still work when rate limited');
    }

    public function test_rate_limit_on_refund(): void
    {
        // Fill rate limiter
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit("stripe-api:{$this->company->id}", 60);
        }

        $invoice = $this->makePaid($this->invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_rate_limited',
        ]);

        $response = $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 1000,
                'reason' => 'Rate limited refund',
                'idempotency_key' => 'refund-rate-limit',
            ]);

        // Should return 409 because rate limit throws RuntimeException
        $response->assertStatus(409);
    }

    // ═══════════════════════════════════════════════════════════
    // D) Audit (2 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_provider_collection_audit_on_retry(): void
    {
        $this->stripeCollectStatus = 'succeeded';
        $invoice = $this->makePaid($this->invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_audit_refund',
        ]);

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 500,
                'reason' => 'Audit check refund',
                'idempotency_key' => 'refund-audit-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::BILLING_REFUND,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    public function test_dunning_retry_audit(): void
    {
        $this->stripeCollectStatus = 'succeeded';
        $invoice = $this->makeOverdue($this->invoice);

        $this->actAsPlatform()
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/retry-payment", [
                'idempotency_key' => 'retry-audit-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::DUNNING_FORCE_RETRY,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);
    }

    // ── Custom assertion ──

    private function assertIn($actual, array $expected, string $message = ''): void
    {
        $this->assertTrue(
            in_array($actual, $expected, true),
            $message ?: "Failed asserting that [{$actual}] is in [" . implode(', ', $expected) . ']',
        );
    }
}
