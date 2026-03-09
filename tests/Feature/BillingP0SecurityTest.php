<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Stripe\StripeEventProcessor;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * P0 Security Corrections — Financial Risk Guards.
 *
 * Tests:
 *   1. Idempotency: charge includes stable idempotency key
 *   2. Idempotency: refund includes stable idempotency key
 *   3. Ownership: batch pay invoice of another company → 403
 *   4. Validation: refund amount > invoice total → 422
 *   5. Validation: credit note amount > invoice total → 422
 *   6. Webhook: charge.dispute.created → event logged
 *   7. Currency: uppercase normalization at adapter boundary
 */
class BillingP0SecurityTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private Company $company;
    private Company $otherCompany;
    private User $owner;
    private Subscription $subscription;
    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        // Company A (owner)
        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'P0 Security Co',
            'slug' => 'p0-security-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->activateCompanyModules($this->company);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        // Company B (different owner)
        $otherOwner = User::factory()->create();
        $this->otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->otherCompany->memberships()->create(['user_id' => $otherOwner->id, 'role' => 'owner']);

        // Platform admin
        $this->admin = PlatformUser::create([
            'first_name' => 'P0',
            'last_name' => 'Admin',
            'email' => 'p0-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ═══════════════════════════════════════════════════════
    // P0-1: IDEMPOTENCY — CHARGE INCLUDES STABLE KEY
    // ═══════════════════════════════════════════════════════

    public function test_charge_idempotency_key_is_stable_and_deterministic(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');
        $capture = new \ArrayObject();

        // Create a Stripe customer for the company
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test_idem',
        ]);

        $adapter = new class($capture) extends StripePaymentAdapter
        {
            private \ArrayObject $capture;

            public function __construct(\ArrayObject $capture)
            {
                $this->capture = $capture;
            }

            protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata, array $opts = [])
            {
                $this->capture['opts'] = $opts;
                $this->capture['currency'] = $currency;

                $intent = new \stdClass();
                $intent->id = 'pi_idem_test';
                $intent->amount_received = $amount;
                $intent->status = 'succeeded';

                return $intent;
            }

            private function setApiKey(): void {}

            private function enforceRateLimit(?int $companyId): void {}
        };

        $result = $adapter->chargeInvoiceWithPaymentMethod($invoice, 'pm_card_123');

        $this->assertEquals('succeeded', $result['status']);
        $this->assertArrayHasKey('idempotency_key', $capture['opts']);
        $this->assertEquals(
            "billing:invoice:{$invoice->id}:charge:pm_card_123",
            $capture['opts']['idempotency_key'],
        );
    }

    // ═══════════════════════════════════════════════════════
    // P0-2: IDEMPOTENCY — REFUND INCLUDES STABLE KEY
    // ═══════════════════════════════════════════════════════

    public function test_refund_idempotency_key_is_stable_and_deterministic(): void
    {
        $capture = new \ArrayObject();

        $adapter = new class($capture) extends StripePaymentAdapter
        {
            private \ArrayObject $capture;

            public function __construct(\ArrayObject $capture)
            {
                $this->capture = $capture;
            }

            protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata, array $opts = []): \Stripe\Refund
            {
                $this->capture['opts'] = $opts;

                $refund = \Stripe\Refund::constructFrom([
                    'id' => 're_test_idem',
                    'amount' => $amount,
                    'status' => 'succeeded',
                ]);

                return $refund;
            }

            private function setApiKey(): void {}

            private function enforceRateLimit(?int $companyId): void {}
        };

        $result = $adapter->refund('pi_original_456', 1500, ['company_id' => $this->company->id]);

        $this->assertEquals('succeeded', $result['status']);
        $this->assertArrayHasKey('idempotency_key', $capture['opts']);
        $this->assertEquals(
            'billing:refund:pi_original_456:1500',
            $capture['opts']['idempotency_key'],
        );
    }

    // ═══════════════════════════════════════════════════════
    // P0-3: OWNERSHIP — BATCH PAY FOREIGN INVOICE → 403
    // ═══════════════════════════════════════════════════════

    public function test_batch_pay_foreign_invoice_returns_403(): void
    {
        // Create invoice for OTHER company
        $otherSub = Subscription::create([
            'company_id' => $this->otherCompany->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $foreignInvoice = InvoiceIssuer::createDraft($this->otherCompany, $otherSub->id);
        InvoiceIssuer::addLine($foreignInvoice, 'plan', 'Starter plan', 1900);
        $foreignInvoice = InvoiceIssuer::finalize($foreignInvoice);

        // Act as owner of Company A, try to pay Company B's invoice
        $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/billing/invoices/pay', [
                'invoice_ids' => [$foreignInvoice->id],
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden: invoice does not belong to this company.');
    }

    public function test_batch_pay_own_invoices_not_blocked_by_ownership(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/billing/invoices/pay', [
                'invoice_ids' => [$invoice->id],
            ]);

        // Should NOT return 403 (may return 422 for missing Stripe customer)
        $this->assertNotEquals(403, $response->status());
    }

    // ═══════════════════════════════════════════════════════
    // P0-4: VALIDATION — REFUND / CREDIT NOTE MAX AMOUNT
    // ═══════════════════════════════════════════════════════

    public function test_refund_exceeding_invoice_total_returns_422(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');

        $this->actingAs($this->admin, 'platform')
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/refund", [
                'amount' => 3000,
                'reason' => 'Excessive refund',
                'idempotency_key' => 'p0-refund-exceed',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Refund amount exceeds invoice total.');
    }

    public function test_credit_note_exceeding_invoice_total_returns_422(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'open');

        $this->actingAs($this->admin, 'platform')
            ->postJson("/api/platform/billing/invoices/{$invoice->id}/credit-note", [
                'amount' => 5000,
                'reason' => 'Excessive credit',
                'apply_to_wallet' => false,
                'idempotency_key' => 'p0-cn-exceed',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Credit note amount exceeds invoice total.');
    }

    // ═══════════════════════════════════════════════════════
    // P0-5: WEBHOOK — DISPUTE CREATED → EVENT LOGGED
    // ═══════════════════════════════════════════════════════

    public function test_dispute_webhook_handled_and_logged(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');
        Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_dispute_test',
            'amount' => 2900,
            'currency' => 'EUR',
            'status' => 'succeeded',
        ]);

        $processor = app(StripeEventProcessor::class);

        $result = $processor->process([
            'id' => 'evt_dispute_1',
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'id' => 'dp_test_123',
                    'charge' => 'ch_test_123',
                    'payment_intent' => 'pi_dispute_test',
                    'amount' => 2900,
                    'currency' => 'eur',
                    'reason' => 'fraudulent',
                ],
            ],
        ]);

        $this->assertTrue($result->handled);
        $this->assertEquals('dispute_created', $result->action);
    }

    public function test_dispute_webhook_does_not_change_subscription(): void
    {
        $invoice = $this->createFinalizedInvoice(2900, 'paid');
        Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_dispute_nosub',
            'amount' => 2900,
            'currency' => 'EUR',
            'status' => 'succeeded',
        ]);

        $statusBefore = $this->subscription->fresh()->status;

        $processor = app(StripeEventProcessor::class);
        $processor->process([
            'id' => 'evt_dispute_2',
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'id' => 'dp_test_456',
                    'charge' => 'ch_test_456',
                    'payment_intent' => 'pi_dispute_nosub',
                    'amount' => 2900,
                    'currency' => 'eur',
                    'reason' => 'product_not_received',
                ],
            ],
        ]);

        $this->assertEquals($statusBefore, $this->subscription->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════
    // P0-6: CURRENCY — LOWERCASE FOR STRIPE, UPPERCASE INTERNAL
    // ═══════════════════════════════════════════════════════

    public function test_caller_passes_uppercase_currency_to_adapter(): void
    {
        $capture = new \ArrayObject();

        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_currency_test',
        ]);

        $adapter = new class($capture) extends StripePaymentAdapter
        {
            private \ArrayObject $capture;

            public function __construct(\ArrayObject $capture)
            {
                $this->capture = $capture;
            }

            protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata, array $opts = [])
            {
                // The parent real method does strtolower internally.
                // Here we capture the raw currency received to verify the caller passes uppercase.
                $this->capture['currency_received'] = $currency;

                $intent = new \stdClass();
                $intent->id = 'pi_curr_test';
                $intent->amount_received = $amount;
                $intent->status = 'succeeded';

                return $intent;
            }

            private function setApiKey(): void {}

            private function enforceRateLimit(?int $companyId): void {}
        };

        $invoice = $this->createFinalizedInvoice(2900, 'open');
        $invoice->update(['currency' => 'EUR']);

        $adapter->chargeInvoiceWithPaymentMethod($invoice, 'pm_test');

        // Caller passes uppercase (ISO 4217), strtolower happens inside the real protected method
        $this->assertEquals('EUR', $capture['currency_received']);
    }

    // ═══════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════

    private function createFinalizedInvoice(int $amount = 2900, string $status = 'open'): Invoice
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', $amount);

        $invoice = InvoiceIssuer::finalize($invoice);

        if ($status !== 'open' && $status !== $invoice->status) {
            $updates = ['status' => $status];

            if ($status === 'paid') {
                $updates['paid_at'] = now();
            }

            $invoice->update($updates);
            $invoice->refresh();
        }

        return $invoice;
    }
}
