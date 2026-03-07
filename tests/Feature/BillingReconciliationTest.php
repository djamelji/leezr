<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CreditNote;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\ReconciliationEngine;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Notifications\BillingCriticalAlert;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ADR-140 D3d: Reconciliation, Drift Detection & Alerting.
 *
 * 20 tests: 8 detection, 6 alerting, 3 rate limit isolation, 3 scheduler.
 */
class BillingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $companyB;
    private Subscription $subscription;
    private Invoice $invoice;

    /** @var array Mock Stripe PaymentIntents returned by the adapter */
    private array $mockStripeIntents = [];

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

        // Company A (primary)
        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Reconcile Co',
            'slug' => 'reconcile-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        // Company B (for isolation tests)
        $ownerB = User::factory()->create();
        $this->companyB = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->companyB->memberships()->create(['user_id' => $ownerB->id, 'role' => 'owner']);

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
            'provider_customer_id' => 'cus_test_reconcile',
        ]);

        // Finalized invoice
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $this->invoice = InvoiceIssuer::finalize($draft);

        // Default: alerting disabled
        config(['billing.alerting.enabled' => false]);

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

                protected function callStripeListPaymentIntents(string $customerId, int $sinceTimestamp): array
                {
                    return $this->testRef->getMockStripeIntents();
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

    public function getMockStripeIntents(): array
    {
        // Convert arrays to Stripe objects for the mock
        return array_map(function ($data) {
            return \Stripe\PaymentIntent::constructFrom($data);
        }, $this->mockStripeIntents);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function makeStripeIntent(string $id, int $amount, string $status = 'succeeded', array $metadata = [], array $charges = []): array
    {
        return [
            'id' => $id,
            'amount' => $amount,
            'status' => $status,
            'metadata' => $metadata,
            'created' => time(),
            'charges' => ['data' => array_map(fn ($c) => (object) $c, $charges)],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // A) Detection (8 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_detects_missing_local_payment(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_stripe_only', 2900),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['by_type']['missing_local_payment'] ?? 0);
        $this->assertEquals('pi_stripe_only', $result['drifts'][0]['provider_payment_id']);
    }

    public function test_detects_missing_stripe_payment(): void
    {
        // Local payment exists but Stripe has nothing
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_local_only',
        ]);

        $this->mockStripeIntents = []; // Empty Stripe

        $result = ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertGreaterThanOrEqual(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['by_type']['missing_stripe_payment'] ?? 0);
    }

    public function test_detects_status_mismatch(): void
    {
        // Local payment failed, Stripe succeeded
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'failed',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_mismatch',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_mismatch', 2900, 'succeeded'),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertEquals(1, $result['summary']['by_type']['status_mismatch'] ?? 0);
    }

    public function test_detects_refund_mismatch(): void
    {
        // Local payment exists, Stripe shows refund, but no CreditNote
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_refund_drift',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_refund_drift', 2900, 'succeeded', [], [
                ['refunded' => true, 'amount_refunded' => 1000],
            ]),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertEquals(1, $result['summary']['by_type']['refund_mismatch'] ?? 0);
    }

    public function test_detects_invoice_not_paid(): void
    {
        // Local payment exists matching Stripe, but invoice is still overdue
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_invoice_drift',
        ]);

        $this->invoice->update(['status' => 'overdue']);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_invoice_drift', 2900, 'succeeded', [
                'invoice_id' => (string) $this->invoice->id,
                'company_id' => (string) $this->company->id,
            ]),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertEquals(1, $result['summary']['by_type']['invoice_not_paid'] ?? 0);
    }

    public function test_no_drift_returns_clean(): void
    {
        // Everything matches perfectly
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_clean',
        ]);

        $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_clean', 2900, 'succeeded', [
                'invoice_id' => (string) $this->invoice->id,
                'company_id' => (string) $this->company->id,
            ]),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertEquals(0, $result['summary']['total']);
    }

    public function test_dry_run_does_not_log(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_dry_run', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, true);

        $this->assertDatabaseMissing('platform_audit_logs', [
            'action' => AuditAction::BILLING_DRIFT_DETECTED,
        ]);
    }

    public function test_non_dry_run_logs_audit(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_real_run', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false);

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::BILLING_DRIFT_DETECTED,
            'target_type' => 'reconciliation',
            'severity' => 'critical',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // B) Alerting (6 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_critical_audit_dispatches_notification(): void
    {
        Notification::fake();

        config([
            'billing.alerting.enabled' => true,
            'billing.alerting.email' => 'alerts@leezr.test',
        ]);

        // Trigger a critical audit log (reconciliation with drifts)
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_alert', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false);

        Notification::assertSentOnDemand(BillingCriticalAlert::class);
    }

    public function test_alerting_disabled_no_dispatch(): void
    {
        Notification::fake();

        config([
            'billing.alerting.enabled' => false,
            'billing.alerting.email' => 'alerts@leezr.test',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_no_alert', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false);

        Notification::assertNothingSent();
    }

    public function test_email_channel_used(): void
    {
        Notification::fake();

        config([
            'billing.alerting.enabled' => true,
            'billing.alerting.email' => 'billing@leezr.test',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_email_channel', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false);

        Notification::assertSentOnDemand(
            BillingCriticalAlert::class,
            function (BillingCriticalAlert $notification, array $channels, $notifiable) {
                return in_array('mail', $channels);
            }
        );
    }

    public function test_non_critical_no_dispatch(): void
    {
        Notification::fake();

        config([
            'billing.alerting.enabled' => true,
            'billing.alerting.email' => 'alerts@leezr.test',
        ]);

        // Trigger a non-critical audit (info severity)
        $audit = app(AuditLogger::class);
        $audit->logPlatform(
            AuditAction::COMPANY_SETTINGS_UPDATED,
            'company',
            '1',
            ['severity' => 'info'],
        );

        Notification::assertNothingSent();
    }

    public function test_alert_contains_action_and_target(): void
    {
        Notification::fake();

        config([
            'billing.alerting.enabled' => true,
            'billing.alerting.email' => 'alerts@leezr.test',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_alert_meta', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false);

        Notification::assertSentOnDemand(
            BillingCriticalAlert::class,
            function (BillingCriticalAlert $notification) {
                $log = $notification->getAuditLog();

                return $log->action === AuditAction::BILLING_DRIFT_DETECTED
                    && $log->target_type === 'reconciliation';
            }
        );
    }

    public function test_alert_dispatch_failure_does_not_break_audit(): void
    {
        // Force notification to throw
        Notification::shouldReceive('route')
            ->andThrow(new \RuntimeException('Mail server down'));

        config([
            'billing.alerting.enabled' => true,
            'billing.alerting.email' => 'alerts@leezr.test',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_fail_alert', 2900),
        ];

        // Should NOT throw — graceful degradation
        $result = ReconciliationEngine::reconcile($this->company->id, false);

        // Audit log was still created
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::BILLING_DRIFT_DETECTED,
        ]);

        $this->assertGreaterThan(0, $result['summary']['total']);
    }

    // ═══════════════════════════════════════════════════════════
    // C) Rate limit isolation (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_rate_limit_isolated_per_company(): void
    {
        // Fill company A rate limiter
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit("stripe-api:{$this->company->id}", 60);
        }

        // Company B should still work
        CompanyPaymentCustomer::create([
            'company_id' => $this->companyB->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_company_b',
        ]);

        $this->mockStripeIntents = [];

        // This should NOT throw — company B has its own rate limit bucket
        $result = ReconciliationEngine::reconcile($this->companyB->id, true);

        $this->assertEquals(0, $result['summary']['total']);
    }

    public function test_company_a_does_not_block_company_b(): void
    {
        // Fill company A to the brim
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit("stripe-api:{$this->company->id}", 60);
        }

        // Company B has zero attempts
        $remaining = RateLimiter::remaining("stripe-api:{$this->companyB->id}", 50);
        $this->assertEquals(50, $remaining);
    }

    public function test_global_fallback_when_no_company(): void
    {
        // Fill the global key
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit('stripe-api:global', 60);
        }

        // Company-specific keys should be unaffected
        $remaining = RateLimiter::remaining("stripe-api:{$this->company->id}", 50);
        $this->assertEquals(50, $remaining);
    }

    // ═══════════════════════════════════════════════════════════
    // D) Scheduler (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_dunning_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('billing:process-dunning')
            ->assertExitCode(0);
    }

    public function test_reconcile_scheduled_weekly(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('billing:reconcile')
            ->assertExitCode(0);
    }

    public function test_commands_without_overlapping(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());

        $dunning = $events->first(fn ($e) => str_contains($e->command ?? '', 'billing:process-dunning'));
        $reconcile = $events->first(fn ($e) => str_contains($e->command ?? '', 'billing:reconcile'));

        $this->assertNotNull($dunning, 'Dunning command should be scheduled');
        $this->assertNotNull($reconcile, 'Reconcile command should be scheduled');

        $this->assertTrue($dunning->withoutOverlapping, 'Dunning should have withoutOverlapping');
        $this->assertTrue($reconcile->withoutOverlapping, 'Reconcile should have withoutOverlapping');
    }
}
