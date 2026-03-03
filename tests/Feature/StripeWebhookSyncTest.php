<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\CreditNote;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class StripeWebhookSyncTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

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

        config(['billing.stripe.webhook_secret' => self::WEBHOOK_SECRET]);

        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Stripe Sync Co',
            'slug' => 'stripe-sync-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        // Finalized invoice (status=open)
        $this->invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($this->invoice, 'plan', 'Pro plan', 2900);
        $this->invoice = InvoiceIssuer::finalize($this->invoice);
    }

    // ── Helpers ──────────────────────────────────────────

    private function intentPayload(string $type = 'payment_intent.succeeded', array $intentOverrides = [], array $eventOverrides = []): string
    {
        $intent = array_merge([
            'id' => 'pi_test_'.uniqid(),
            'amount_received' => $this->invoice->amount_due,
            'amount' => $this->invoice->amount_due,
            'currency' => 'eur',
            'customer' => 'cus_test_123',
            'payment_method' => 'pm_card_visa',
            'metadata' => [
                'company_id' => (string) $this->company->id,
                'invoice_id' => (string) $this->invoice->id,
            ],
            'last_payment_error' => null,
        ], $intentOverrides);

        return json_encode(array_merge([
            'id' => 'evt_test_'.uniqid(),
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $intent],
        ], $eventOverrides));
    }

    private function chargePayload(string $paymentIntentId, array $refunds, array $eventOverrides = []): string
    {
        return json_encode(array_merge([
            'id' => 'evt_test_'.uniqid(),
            'type' => 'charge.refunded',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'ch_test_'.uniqid(),
                    'payment_intent' => $paymentIntentId,
                    'refunds' => ['data' => $refunds],
                ],
            ],
        ], $eventOverrides));
    }

    private function validSignatureHeader(string $payload): string
    {
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, self::WEBHOOK_SECRET);

        return "t={$timestamp},v1={$signature}";
    }

    private function postStripeWebhook(string $payload): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/webhooks/payments/stripe',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => $this->validSignatureHeader($payload),
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
    }

    // ── A) payment_intent.succeeded ──────────────────────

    public function test_succeeded_creates_payment_and_marks_invoice_paid(): void
    {
        $piId = 'pi_success_'.uniqid();
        $payload = $this->intentPayload('payment_intent.succeeded', ['id' => $piId]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => true]);

        $this->assertDatabaseHas('payments', [
            'provider_payment_id' => $piId,
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'succeeded',
            'provider' => 'stripe',
        ]);

        $this->invoice->refresh();
        $this->assertEquals('paid', $this->invoice->status);
        $this->assertNotNull($this->invoice->paid_at);
    }

    public function test_succeeded_idempotent_on_duplicate_payment_intent(): void
    {
        $piId = 'pi_idem_'.uniqid();

        $payload1 = $this->intentPayload('payment_intent.succeeded', ['id' => $piId]);
        $this->postStripeWebhook($payload1)->assertOk();

        // Second event, different event_id, same pi
        $payload2 = $this->intentPayload('payment_intent.succeeded', ['id' => $piId]);
        $this->postStripeWebhook($payload2)->assertOk();

        $this->assertEquals(1, Payment::where('provider_payment_id', $piId)->count());

        $this->invoice->refresh();
        $this->assertEquals('paid', $this->invoice->status);
    }

    public function test_succeeded_ignored_without_invoice_metadata(): void
    {
        $payload = $this->intentPayload('payment_intent.succeeded', [
            'metadata' => ['company_id' => (string) $this->company->id],
        ]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => false]);

        $this->assertEquals(0, Payment::count());

        $this->invoice->refresh();
        $this->assertEquals('open', $this->invoice->status);
    }

    public function test_succeeded_ignored_when_invoice_void(): void
    {
        $this->invoice->update(['status' => 'void', 'voided_at' => now()]);

        $payload = $this->intentPayload('payment_intent.succeeded');

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();

        // Payment created but invoice NOT mutated (void stays void)
        $this->invoice->refresh();
        $this->assertEquals('void', $this->invoice->status);
    }

    public function test_succeeded_noop_when_invoice_already_paid(): void
    {
        $this->invoice->update(['status' => 'paid', 'paid_at' => now()->subHour()]);
        $originalPaidAt = $this->invoice->fresh()->paid_at;

        $payload = $this->intentPayload('payment_intent.succeeded');

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => true]);

        $this->invoice->refresh();
        $this->assertEquals('paid', $this->invoice->status);
        // paid_at not overwritten
        $this->assertEquals($originalPaidAt->toDateTimeString(), $this->invoice->paid_at->toDateTimeString());
    }

    // ── B) payment_intent.payment_failed ─────────────────

    public function test_failed_creates_payment_and_marks_invoice_overdue(): void
    {
        $piId = 'pi_fail_'.uniqid();
        $payload = $this->intentPayload('payment_intent.payment_failed', [
            'id' => $piId,
            'last_payment_error' => [
                'code' => 'card_declined',
                'message' => 'Your card was declined.',
            ],
        ]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => true]);

        $this->assertDatabaseHas('payments', [
            'provider_payment_id' => $piId,
            'status' => 'failed',
            'invoice_id' => $this->invoice->id,
        ]);

        $this->invoice->refresh();
        $this->assertEquals('overdue', $this->invoice->status);
    }

    public function test_failed_noop_when_invoice_already_overdue(): void
    {
        $this->invoice->update(['status' => 'overdue']);

        $payload = $this->intentPayload('payment_intent.payment_failed');

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();

        $this->invoice->refresh();
        $this->assertEquals('overdue', $this->invoice->status);
    }

    public function test_failed_noop_when_invoice_paid(): void
    {
        $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $payload = $this->intentPayload('payment_intent.payment_failed');

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();

        $this->invoice->refresh();
        $this->assertEquals('paid', $this->invoice->status);
    }

    public function test_failed_ignored_without_invoice_metadata(): void
    {
        $payload = $this->intentPayload('payment_intent.payment_failed', [
            'metadata' => ['company_id' => (string) $this->company->id],
        ]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => false]);

        $this->assertEquals(0, Payment::count());
    }

    // ── C) charge.refunded ───────────────────────────────

    public function test_refund_creates_credit_note_issued(): void
    {
        // Pre-create the payment that the refund references
        $piId = 'pi_refund_'.uniqid();
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => $piId,
        ]);

        $refundId = 're_test_'.uniqid();
        $payload = $this->chargePayload($piId, [
            ['id' => $refundId, 'amount' => 1000, 'status' => 'succeeded'],
        ]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => true]);

        $cn = CreditNote::where('invoice_id', $this->invoice->id)
            ->whereJsonContains('metadata->provider_refund_id', $refundId)
            ->first();

        $this->assertNotNull($cn);
        $this->assertEquals('issued', $cn->status);
        $this->assertEquals(1000, $cn->amount);
        $this->assertNull($cn->wallet_transaction_id); // No wallet apply in D3b

        // Wallet balance unchanged
        $this->assertEquals(0, WalletLedger::balance($this->company));
    }

    public function test_refund_idempotent_on_duplicate_refund_id(): void
    {
        $piId = 'pi_refund_idem_'.uniqid();
        Payment::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => $piId,
        ]);

        $refundId = 're_idem_'.uniqid();
        $refunds = [['id' => $refundId, 'amount' => 500, 'status' => 'succeeded']];

        $payload1 = $this->chargePayload($piId, $refunds);
        $this->postStripeWebhook($payload1)->assertOk();

        $payload2 = $this->chargePayload($piId, $refunds);
        $this->postStripeWebhook($payload2)->assertOk();

        $count = CreditNote::where('invoice_id', $this->invoice->id)
            ->whereJsonContains('metadata->provider_refund_id', $refundId)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_refund_ignored_without_matching_payment(): void
    {
        $payload = $this->chargePayload('pi_nonexistent', [
            ['id' => 're_orphan', 'amount' => 500, 'status' => 'succeeded'],
        ]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();
        $response->assertJson(['handled' => false]);

        $this->assertEquals(0, CreditNote::count());
    }

    // ── D) Edge cases + Audit ────────────────────────────

    public function test_unhandled_event_type_returns_ignored(): void
    {
        $payload = json_encode([
            'id' => 'evt_unhandled_'.uniqid(),
            'type' => 'customer.created',
            'created' => time(),
            'data' => ['object' => ['id' => 'cus_test']],
        ]);

        $response = $this->postStripeWebhook($payload);

        $response->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'event_type' => 'customer.created',
            'status' => 'ignored',
        ]);
    }

    public function test_audit_logs_created_for_handled_events(): void
    {
        // 1. Payment succeeded
        $piSuccessId = 'pi_audit_success_'.uniqid();
        $payloadSuccess = $this->intentPayload('payment_intent.succeeded', ['id' => $piSuccessId]);
        $this->postStripeWebhook($payloadSuccess)->assertOk();

        // 2. Payment failed (need fresh invoice)
        $invoice2 = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice2, 'plan', 'Pro plan', 1500);
        $invoice2 = InvoiceIssuer::finalize($invoice2);

        $piFailId = 'pi_audit_fail_'.uniqid();
        $payloadFail = $this->intentPayload('payment_intent.payment_failed', [
            'id' => $piFailId,
            'metadata' => [
                'company_id' => (string) $this->company->id,
                'invoice_id' => (string) $invoice2->id,
            ],
        ]);
        $this->postStripeWebhook($payloadFail)->assertOk();

        // 3. Refund
        $piRefundId = 'pi_audit_refund_'.uniqid();
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 2900,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => $piRefundId,
        ]);
        $payloadRefund = $this->chargePayload($piRefundId, [
            ['id' => 're_audit_'.uniqid(), 'amount' => 500, 'status' => 'succeeded'],
        ]);
        $this->postStripeWebhook($payloadRefund)->assertOk();

        // Assert audit logs
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::WEBHOOK_PAYMENT_SYNCED,
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::WEBHOOK_PAYMENT_FAILED,
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::WEBHOOK_REFUND_SYNCED,
        ]);
    }
}
