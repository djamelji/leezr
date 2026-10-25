<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\Payment;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-614 (LOT 2 — vérité financière). The owner overview must not present a not-yet-due invoice as a
 * payment problem, must count PARENT invoices only (annexes aren't standalone bills), and must flag a
 * real problem only on an overdue document or a failed payment.
 */
class BillingFinancialTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Core\Markets\MarketRegistry::sync(); // FR/GB markets for the market_key FK
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(), 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function inv(Company $c, array $o): Invoice
    {
        return Invoice::create(array_merge([
            'company_id' => $c->id, 'number' => uniqid('INV'), 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(),
        ], $o));
    }

    public function test_not_due_invoices_are_not_a_payment_issue(): void
    {
        $c = $this->company('NotDue Co');
        // One parent invoice + 2 annexes, all open, due in the future.
        $p = $this->inv($c, ['parent_invoice_id' => null, 'due_at' => now()->addMonth()]);
        $this->inv($c, ['parent_invoice_id' => $p->id, 'annexe_suffix' => 'A', 'due_at' => now()->addMonth()]);
        $this->inv($c, ['parent_invoice_id' => $p->id, 'annexe_suffix' => 'B', 'due_at' => now()->addMonth()]);

        $ov = CompanyBillingReadService::overview($c);

        $this->assertSame(1, $ov['invoices_not_due'], 'parent invoices only (annexes excluded)');
        $this->assertSame(0, $ov['invoices_overdue']);
        $this->assertFalse($ov['has_failed_payment']);
        $this->assertFalse($ov['has_payment_issue'], 'a not-yet-due invoice is NOT a payment problem');
    }

    public function test_overdue_invoice_is_a_payment_issue(): void
    {
        $c = $this->company('Overdue Co');
        $this->inv($c, ['status' => 'overdue', 'due_at' => now()->subDays(5)]);

        $ov = CompanyBillingReadService::overview($c);

        $this->assertSame(1, $ov['invoices_overdue']);
        $this->assertTrue($ov['has_payment_issue']);
    }

    public function test_open_invoice_past_due_date_counts_as_overdue(): void
    {
        $c = $this->company('PastDue Co');
        $this->inv($c, ['status' => 'open', 'due_at' => now()->subDay()]);

        $ov = CompanyBillingReadService::overview($c);

        $this->assertSame(0, $ov['invoices_not_due']);
        $this->assertSame(1, $ov['invoices_overdue']);
        $this->assertTrue($ov['has_payment_issue']);
    }

    public function test_void_and_written_off_invoices_are_not_exigible(): void
    {
        // ADR-619: cancelled / written-off / uncollectible invoices must NEVER be counted as due or
        // presented as a payment problem.
        $c = $this->company('Void Co');
        $this->inv($c, ['status' => 'void', 'amount_due' => 0, 'due_at' => now()->subDays(5)]);
        $this->inv($c, ['status' => 'written_off', 'due_at' => now()->subDays(5)]);
        $this->inv($c, ['status' => 'uncollectible', 'due_at' => now()->subDays(5)]);

        $ov = CompanyBillingReadService::overview($c);

        $this->assertSame(0, $ov['invoices_not_due']);
        $this->assertSame(0, $ov['invoices_overdue'], 'void/written_off/uncollectible are not overdue');
        $this->assertFalse($ov['has_payment_issue'], 'a cancelled/written-off invoice is not a payment problem');
    }

    public function test_can_pay_online_only_for_stripe_provider(): void
    {
        // ADR-623: self-service payment is possible only when the provider can collect (Stripe).
        $stripe = $this->company('Stripe Co');
        Subscription::create(['company_id' => $stripe->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'provider' => 'stripe', 'is_current' => true, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->assertTrue(CompanyBillingReadService::overview($stripe)['can_pay_online']);

        $internal = $this->company('Internal Co');
        Subscription::create(['company_id' => $internal->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'provider' => 'internal', 'is_current' => true, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $this->assertFalse(CompanyBillingReadService::overview($internal)['can_pay_online'], 'internal provider cannot self-service pay');

        $none = $this->company('No Sub Co');
        $this->assertFalse(CompanyBillingReadService::overview($none)['can_pay_online'], 'no subscription → no online payment');
    }

    public function test_failed_payment_on_open_invoice_is_a_payment_issue(): void
    {
        $c = $this->company('Failed Co');
        $sub = Subscription::create(['company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'provider' => 'stripe', 'is_current' => true, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $i = $this->inv($c, ['status' => 'open', 'due_at' => now()->addMonth()]);
        Payment::create(['subscription_id' => $sub->id, 'invoice_id' => $i->id, 'company_id' => $c->id, 'amount' => 1200, 'currency' => 'EUR', 'status' => 'failed', 'provider' => 'stripe', 'provider_payment_id' => 'pi_x']);

        $ov = CompanyBillingReadService::overview($c);

        $this->assertTrue($ov['has_failed_payment']);
        $this->assertTrue($ov['has_payment_issue'], 'a failed payment is a real problem even if the invoice is not yet due');
    }
}
