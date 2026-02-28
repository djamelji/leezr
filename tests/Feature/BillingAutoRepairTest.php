<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\AutoRepairEngine;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyWallet;
use App\Core\Billing\CompanyWalletTransaction;
use App\Core\Billing\CreditNote;
use App\Core\Billing\FinancialForensicsService;
use App\Core\Billing\FinancialSnapshot;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-141 D3e: Auto-Repair & Financial Forensics.
 *
 * 25 tests: 10 auto-repair, 5 snapshot, 4 idempotency, 3 config/dry-run, 3 forensics.
 */
class BillingAutoRepairTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
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

        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'AutoRepair Co',
            'slug' => 'autorepair-co',
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
            'provider_customer_id' => 'cus_test_autorepair',
        ]);

        // Finalized invoice
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $this->invoice = InvoiceIssuer::finalize($draft);

        // Default: auto-repair enabled, alerting disabled
        config([
            'billing.auto_repair.enabled' => true,
            'billing.auto_repair.dry_run_default' => true,
            'billing.auto_repair.safe_types' => [
                'missing_local_payment',
                'status_mismatch',
                'invoice_not_paid',
            ],
            'billing.alerting.enabled' => false,
        ]);

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

    public function getMockStripeIntents(): array
    {
        return array_map(function ($data) {
            return \Stripe\PaymentIntent::constructFrom($data);
        }, $this->mockStripeIntents);
    }

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
    // A) Auto-repair: missing_local_payment (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_repairs_missing_local_payment(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_missing', 2900),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, false, true);

        $this->assertArrayHasKey('repairs', $result);
        $this->assertCount(1, $result['repairs']['repaired']);
        $this->assertEquals('missing_local_payment', $result['repairs']['repaired'][0]['type']);
        $this->assertEquals('created_payment', $result['repairs']['repaired'][0]['action']);

        // Payment was created in DB
        $this->assertDatabaseHas('payments', [
            'provider_payment_id' => 'pi_missing',
            'company_id' => $this->company->id,
            'status' => 'succeeded',
            'provider' => 'stripe',
        ]);
    }

    public function test_missing_local_payment_creates_correct_amount(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_amount_check', 5500),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $payment = Payment::where('provider_payment_id', 'pi_amount_check')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(5500, $payment->amount);
        $this->assertTrue($payment->metadata['auto_repaired'] ?? false);
    }

    public function test_missing_local_payment_links_to_subscription(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_sub_link', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $payment = Payment::where('provider_payment_id', 'pi_sub_link')->first();
        $this->assertNotNull($payment);
        $this->assertEquals($this->subscription->id, $payment->subscription_id);
    }

    // ═══════════════════════════════════════════════════════════
    // B) Auto-repair: status_mismatch (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_repairs_status_mismatch(): void
    {
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
            $this->makeStripeIntent('pi_mismatch', 2900),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, false, true);

        $repaired = collect($result['repairs']['repaired'])->firstWhere('type', 'status_mismatch');
        $this->assertNotNull($repaired);
        $this->assertEquals('updated_status', $repaired['action']);
        $this->assertEquals('failed', $repaired['from']);
        $this->assertEquals('succeeded', $repaired['to']);

        // DB updated
        $this->assertDatabaseHas('payments', [
            'provider_payment_id' => 'pi_mismatch',
            'status' => 'succeeded',
        ]);
    }

    public function test_status_mismatch_stores_previous_status_in_metadata(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'pending',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_meta_check',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_meta_check', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $payment = Payment::where('provider_payment_id', 'pi_meta_check')->first();
        $this->assertEquals('pending', $payment->metadata['previous_status']);
        $this->assertTrue($payment->metadata['auto_repaired']);
    }

    public function test_status_mismatch_skips_when_no_payment_found(): void
    {
        // Drift referencing a payment that doesn't exist in DB
        $drifts = [[
            'type' => 'status_mismatch',
            'provider_payment_id' => 'pi_ghost',
            'company_id' => $this->company->id,
            'details' => ['stripe_status' => 'succeeded', 'local_status' => 'failed'],
        ]];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertCount(1, $result['repaired']);
        $this->assertEquals('skipped_no_payment', $result['repaired'][0]['action']);
    }

    // ═══════════════════════════════════════════════════════════
    // C) Auto-repair: invoice_not_paid (4 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_repairs_invoice_not_paid(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_invoice_fix',
        ]);

        $this->invoice->update(['status' => 'overdue']);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_invoice_fix', 2900, 'succeeded', [
                'invoice_id' => (string) $this->invoice->id,
                'company_id' => (string) $this->company->id,
            ]),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, false, true);

        $repaired = collect($result['repairs']['repaired'])->firstWhere('type', 'invoice_not_paid');
        $this->assertNotNull($repaired);
        $this->assertEquals('marked_paid', $repaired['action']);

        $this->assertDatabaseHas('invoices', [
            'id' => $this->invoice->id,
            'status' => 'paid',
        ]);
    }

    public function test_invoice_not_paid_sets_paid_at(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_paid_at',
        ]);

        $this->invoice->update(['status' => 'overdue']);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_paid_at', 2900, 'succeeded', [
                'invoice_id' => (string) $this->invoice->id,
            ]),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $this->invoice->refresh();
        $this->assertNotNull($this->invoice->paid_at);
        $this->assertEquals('paid', $this->invoice->status);
    }

    public function test_invoice_not_paid_skips_missing_invoice(): void
    {
        $drifts = [[
            'type' => 'invoice_not_paid',
            'provider_payment_id' => 'pi_no_inv',
            'company_id' => $this->company->id,
            'details' => ['invoice_id' => 99999, 'invoice_status' => 'overdue'],
        ]];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertCount(1, $result['repaired']);
        $this->assertEquals('skipped_invoice_not_found', $result['repaired'][0]['action']);
    }

    public function test_invoice_not_paid_skips_no_invoice_id(): void
    {
        $drifts = [[
            'type' => 'invoice_not_paid',
            'provider_payment_id' => 'pi_no_id',
            'company_id' => $this->company->id,
            'details' => [],
        ]];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertCount(1, $result['repaired']);
        $this->assertEquals('skipped_no_invoice_id', $result['repaired'][0]['action']);
    }

    // ═══════════════════════════════════════════════════════════
    // D) Snapshots (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_missing_local_payment_creates_snapshot(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_snap_missing', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $this->assertDatabaseHas('financial_snapshots', [
            'company_id' => $this->company->id,
            'trigger' => 'auto_repair',
            'drift_type' => 'missing_local_payment',
            'entity_type' => 'payment',
            'entity_id' => 'pi_snap_missing',
        ]);
    }

    public function test_status_mismatch_creates_snapshot_before_mutation(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'failed',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_snap_status',
        ]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_snap_status', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $snapshot = FinancialSnapshot::where('drift_type', 'status_mismatch')
            ->where('entity_type', 'payment')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEquals('failed', $snapshot->snapshot_data['status']);
    }

    public function test_invoice_not_paid_creates_snapshot(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_snap_inv',
        ]);

        $this->invoice->update(['status' => 'overdue']);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_snap_inv', 2900, 'succeeded', [
                'invoice_id' => (string) $this->invoice->id,
            ]),
        ];

        ReconciliationEngine::reconcile($this->company->id, false, true);

        $snapshot = FinancialSnapshot::where('drift_type', 'invoice_not_paid')
            ->where('entity_type', 'invoice')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEquals('overdue', $snapshot->snapshot_data['status']);
    }

    public function test_snapshot_has_correlation_id(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_corr', 2900),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, false, true);

        $snapshot = FinancialSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertNotNull($snapshot->correlation_id);
        $this->assertEquals($result['repairs']['correlation_id'], $snapshot->correlation_id);
    }

    public function test_dry_run_does_not_create_snapshots(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_dry_snap', 2900),
        ];

        ReconciliationEngine::reconcile($this->company->id, true, true);

        $this->assertEquals(0, FinancialSnapshot::count());
    }

    // ═══════════════════════════════════════════════════════════
    // E) Idempotency (4 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_missing_local_payment_idempotent(): void
    {
        // Create the payment first
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_already_exists',
        ]);

        $drifts = [[
            'type' => 'missing_local_payment',
            'provider_payment_id' => 'pi_already_exists',
            'company_id' => $this->company->id,
            'details' => ['stripe_amount' => 2900, 'stripe_status' => 'succeeded'],
        ]];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertEquals('skipped_idempotent', $result['repaired'][0]['action']);
        // No duplicate payment
        $this->assertEquals(1, Payment::where('provider_payment_id', 'pi_already_exists')->count());
    }

    public function test_status_mismatch_idempotent(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded', // Already correct
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_already_ok',
        ]);

        $drifts = [[
            'type' => 'status_mismatch',
            'provider_payment_id' => 'pi_already_ok',
            'company_id' => $this->company->id,
            'details' => ['stripe_status' => 'succeeded', 'local_status' => 'failed'],
        ]];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertEquals('skipped_idempotent', $result['repaired'][0]['action']);
    }

    public function test_invoice_not_paid_idempotent(): void
    {
        $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $drifts = [[
            'type' => 'invoice_not_paid',
            'provider_payment_id' => 'pi_inv_ok',
            'company_id' => $this->company->id,
            'details' => ['invoice_id' => $this->invoice->id, 'invoice_status' => 'overdue'],
        ]];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertEquals('skipped_idempotent', $result['repaired'][0]['action']);
    }

    public function test_double_repair_is_safe(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_double', 2900),
        ];

        // First repair
        ReconciliationEngine::reconcile($this->company->id, false, true);
        $this->assertEquals(1, Payment::where('provider_payment_id', 'pi_double')->count());

        // Second repair — should be idempotent
        $result = ReconciliationEngine::reconcile($this->company->id, false, true);

        // No drift on second run because payment now exists
        $this->assertEquals(0, $result['summary']['total']);
        $this->assertEquals(1, Payment::where('provider_payment_id', 'pi_double')->count());
    }

    // ═══════════════════════════════════════════════════════════
    // F) Config & dry-run (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_auto_repair_disabled_skips_repairs(): void
    {
        config(['billing.auto_repair.enabled' => false]);

        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_disabled', 2900),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, false, true);

        $this->assertArrayNotHasKey('repairs', $result);
        $this->assertDatabaseMissing('payments', ['provider_payment_id' => 'pi_disabled']);
    }

    public function test_unsafe_drift_types_are_skipped(): void
    {
        $drifts = [
            [
                'type' => 'missing_stripe_payment',
                'provider_payment_id' => 'pi_unsafe_1',
                'company_id' => $this->company->id,
                'details' => [],
            ],
            [
                'type' => 'refund_mismatch',
                'provider_payment_id' => 'pi_unsafe_2',
                'company_id' => $this->company->id,
                'details' => [],
            ],
        ];

        $result = AutoRepairEngine::repair($drifts, false);

        $this->assertCount(0, $result['repaired']);
        $this->assertCount(2, $result['skipped']);
        $this->assertEquals('unsafe_type', $result['skipped'][0]['reason']);
        $this->assertEquals('unsafe_type', $result['skipped'][1]['reason']);
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $this->mockStripeIntents = [
            $this->makeStripeIntent('pi_dry', 2900),
        ];

        $result = ReconciliationEngine::reconcile($this->company->id, true, true);

        // Repairs should still compute what would happen
        $this->assertArrayHasKey('repairs', $result);
        $this->assertCount(1, $result['repairs']['repaired']);
        $this->assertEquals('would_create_payment', $result['repairs']['repaired'][0]['action']);

        // But no actual mutation
        $this->assertDatabaseMissing('payments', ['provider_payment_id' => 'pi_dry']);
        $this->assertEquals(0, FinancialSnapshot::count());
    }

    // ═══════════════════════════════════════════════════════════
    // G) Forensics (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_forensics_timeline_includes_all_entity_types(): void
    {
        // Create entities for the timeline
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_timeline',
        ]);

        CreditNote::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'number' => 'CN-001',
            'amount' => 500,
            'currency' => 'eur',
            'reason' => 'test',
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        FinancialSnapshot::create([
            'company_id' => $this->company->id,
            'trigger' => 'auto_repair',
            'drift_type' => 'status_mismatch',
            'entity_type' => 'payment',
            'entity_id' => '1',
            'snapshot_data' => ['status' => 'failed'],
            'created_at' => now(),
        ]);

        $timeline = FinancialForensicsService::timeline($this->company->id);

        $types = array_unique(array_column($timeline, 'entity_type'));
        $this->assertContains('invoice', $types);
        $this->assertContains('payment', $types);
        $this->assertContains('credit_note', $types);
        $this->assertContains('snapshot', $types);
    }

    public function test_forensics_timeline_is_sorted_chronologically(): void
    {
        // Create multiple entities with distinct timestamps
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_chrono',
        ]);

        $timeline = FinancialForensicsService::timeline($this->company->id);

        $this->assertGreaterThanOrEqual(2, count($timeline), 'Timeline should have at least 2 entries');

        for ($i = 1; $i < count($timeline); $i++) {
            $this->assertGreaterThanOrEqual(
                $timeline[$i - 1]['timestamp'],
                $timeline[$i]['timestamp'],
                'Timeline entries should be sorted chronologically'
            );
        }
    }

    public function test_forensics_timeline_filters_by_entity_type(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'amount' => 2900,
            'currency' => 'eur',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_filter',
        ]);

        $paymentOnly = FinancialForensicsService::timeline($this->company->id, 30, 'payment');
        $invoiceOnly = FinancialForensicsService::timeline($this->company->id, 30, 'invoice');

        $paymentTypes = array_unique(array_column($paymentOnly, 'entity_type'));
        $invoiceTypes = array_unique(array_column($invoiceOnly, 'entity_type'));

        $this->assertEquals(['payment'], $paymentTypes);
        $this->assertEquals(['invoice'], $invoiceTypes);
    }
}
