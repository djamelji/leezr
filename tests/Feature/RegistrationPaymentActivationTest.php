<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * P0-B (ADR-734) — Embedded registration payment must not create a
 * paid-looking state without a proven encaissement.
 *
 * ConfirmRegistrationPaymentController previously flipped a pending_payment
 * subscription to `active` as soon as a card was saved (SetupIntent),
 * independently of whether the first term was actually charged — leaving an
 * active subscription with an OPEN (unpaid) plan invoice (phantom-paid).
 *
 * The subscription must now activate ONLY after the plan charge succeeds.
 */
class RegistrationPaymentActivationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Subscription $subscription;

    /** @var 'succeeded'|'failed' */
    public string $chargeStatus = 'succeeded';

    public int $chargeCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Reg Pay Co',
            'slug' => 'reg-pay-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        // Stripe customer so chargeInvoiceWithPaymentMethod does not early-fail.
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test_regpay',
        ]);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'pending_payment',
            'provider' => 'stripe',
            'interval' => 'monthly',
        ]);

        $this->bindMockAdapter();
    }

    private function bindMockAdapter(): void
    {
        $test = $this;

        $this->app->bind(StripePaymentAdapter::class, function () use ($test) {
            return new class($test) extends StripePaymentAdapter
            {
                private $t;

                public function __construct($t)
                {
                    $this->t = $t;
                }

                protected function callStripeRetrievePaymentMethod(string $paymentMethodId)
                {
                    return \Stripe\PaymentMethod::constructFrom([
                        'id' => $paymentMethodId,
                        'type' => 'card',
                        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'fingerprint' => 'fp_test', 'country' => 'FR', 'funding' => 'credit'],
                        'billing_details' => ['name' => 'Test Owner'],
                    ]);
                }

                protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata, array $opts = [])
                {
                    $this->t->chargeCalls++;
                    $status = $this->t->chargeStatus;

                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_regpay_'.uniqid(),
                        'amount' => $amount,
                        'amount_received' => $status === 'succeeded' ? $amount : 0,
                        'currency' => $currency,
                        'status' => $status,
                        'metadata' => $metadata,
                    ]);
                }
            };
        });
    }

    private function confirm(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/register/confirm-payment', [
            'payment_method_id' => 'pm_test_card',
            'subscription_id' => $this->subscription->id,
        ]);
    }

    // ── Happy path: real charge success → active + invoice paid ──
    public function test_successful_charge_activates_subscription_and_pays_invoice(): void
    {
        $this->chargeStatus = 'succeeded';

        $this->confirm()
            ->assertOk()
            ->assertJsonFragment(['subscription_status' => 'active']);

        $this->assertSame('active', $this->subscription->fresh()->status);

        $invoice = Invoice::where('company_id', $this->company->id)
            ->where('subscription_id', $this->subscription->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    // ── Phantom-paid regression: charge fails → NOT active, invoice stays open ──
    public function test_failed_charge_keeps_pending_payment_and_invoice_open(): void
    {
        $this->chargeStatus = 'failed';

        $this->confirm()->assertStatus(402);

        $this->assertSame(
            'pending_payment',
            $this->subscription->fresh()->status,
            'a saved card without a successful charge must NOT activate the subscription'
        );

        $invoice = Invoice::where('company_id', $this->company->id)
            ->where('subscription_id', $this->subscription->id)->first();
        $this->assertNotNull($invoice, 'the plan invoice is issued');
        $this->assertSame('open', $invoice->status, 'unpaid invoice must stay open, never marked paid');
        $this->assertNull($invoice->paid_at);
    }

    // ── Retry after a failed charge must not duplicate the plan invoice ──
    public function test_retry_after_failed_charge_does_not_duplicate_invoice(): void
    {
        $this->chargeStatus = 'failed';
        $this->confirm()->assertStatus(402);

        $this->chargeStatus = 'succeeded';
        $this->confirm()->assertOk();

        $this->assertSame('active', $this->subscription->fresh()->status);

        $count = Invoice::where('company_id', $this->company->id)
            ->where('subscription_id', $this->subscription->id)->count();
        $this->assertSame(1, $count, 'retry reuses the open invoice instead of creating a duplicate');
    }
}
