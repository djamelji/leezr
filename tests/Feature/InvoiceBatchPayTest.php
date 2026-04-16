<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

class InvoiceBatchPayTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($this->company);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => true,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
        ]);

        // Ensure wallet exists
        WalletLedger::ensureWallet($this->company);
    }

    private function createInvoice(string $status = 'open', int $amountDue = 5000): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'number' => 'INV-' . fake()->unique()->numberBetween(1000, 9999),
            'status' => $status,
            'subtotal' => $amountDue,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'amount' => $amountDue,
            'amount_due' => $amountDue,
            'currency' => 'EUR',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'due_at' => $status === 'overdue' ? now()->subWeek() : now()->addMonth(),
            'finalized_at' => now(),
            'issued_at' => now(),
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Pro Plan',
            'type' => 'charge',
            'quantity' => 1,
            'unit_amount' => $amountDue,
            'amount' => $amountDue,
        ]);

        return $invoice;
    }

    private function actAsCompany()
    {
        return $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ── Outstanding endpoint ──

    public function test_outstanding_returns_unpaid_invoices(): void
    {
        $open = $this->createInvoice('open', 3000);
        $overdue = $this->createInvoice('overdue', 5000);
        $paid = $this->createInvoice('open', 2000);
        $paid->update(['status' => 'paid', 'paid_at' => now(), 'amount_due' => 0]);

        $response = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding');

        $response->assertOk()
            ->assertJsonCount(2, 'invoices')
            ->assertJsonStructure([
                'invoices' => [['id', 'number', 'status', 'amount_due', 'currency']],
                'wallet_balance',
                'currency',
            ]);

        $ids = collect($response->json('invoices'))->pluck('id')->all();
        $this->assertContains($open->id, $ids);
        $this->assertContains($overdue->id, $ids);
    }

    public function test_outstanding_excludes_other_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        Invoice::create([
            'company_id' => $otherCompany->id,
            'subscription_id' => $this->subscription->id,
            'number' => 'INV-OTHER',
            'status' => 'open',
            'subtotal' => 5000,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'amount' => 5000,
            'amount_due' => 5000,
            'currency' => 'EUR',
            'due_at' => now()->addMonth(),
            'finalized_at' => now(),
            'issued_at' => now(),
        ]);

        $response = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding');

        $response->assertOk();
        $ids = collect($response->json('invoices'))->pluck('id')->all();
        // Should be empty — this company has no invoices
        $this->assertEmpty($ids);
    }

    public function test_outstanding_includes_uncollectible(): void
    {
        $uncollectible = $this->createInvoice('open', 4000);
        $uncollectible->update(['status' => 'uncollectible']);

        $response = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding');

        $response->assertOk();
        $ids = collect($response->json('invoices'))->pluck('id')->all();
        $this->assertContains($uncollectible->id, $ids);
    }

    // ── Pay endpoint ──

    public function test_pay_requires_invoice_ids(): void
    {
        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', []);

        $response->assertStatus(422);
    }

    public function test_pay_rejects_nonexistent_invoices(): void
    {
        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', [
            'invoice_ids' => [99999],
        ]);

        $response->assertStatus(422);
    }

    public function test_pay_rejects_paid_invoices(): void
    {
        $invoice = $this->createInvoice('open', 3000);
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'amount_due' => 0]);

        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', [
            'invoice_ids' => [$invoice->id],
        ]);

        $response->assertStatus(422);
    }

    public function test_wallet_covers_all_marks_invoices_paid(): void
    {
        $invoice = $this->createInvoice('open', 3000);

        // Add enough wallet credit
        WalletLedger::credit(
            company: $this->company,
            amount: 5000,
            sourceType: 'test',
            sourceId: 1,
            description: 'Test credit',
        );

        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', [
            'invoice_ids' => [$invoice->id],
            'use_wallet' => true,
        ]);

        $response->assertOk()
            ->assertJson(['mode' => 'wallet_paid']);

        $this->assertContains($invoice->id, $response->json('paid_invoice_ids'));

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_wallet_covers_multiple_invoices(): void
    {
        $inv1 = $this->createInvoice('open', 2000);
        $inv2 = $this->createInvoice('overdue', 3000);

        WalletLedger::credit(
            company: $this->company,
            amount: 10000,
            sourceType: 'test',
            sourceId: 1,
            description: 'Test credit',
        );

        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', [
            'invoice_ids' => [$inv1->id, $inv2->id],
            'use_wallet' => true,
        ]);

        $response->assertOk()
            ->assertJson(['mode' => 'wallet_paid']);

        $paidIds = $response->json('paid_invoice_ids');
        $this->assertContains($inv1->id, $paidIds);
        $this->assertContains($inv2->id, $paidIds);

        $inv1->refresh();
        $inv2->refresh();
        $this->assertEquals('paid', $inv1->status);
        $this->assertEquals('paid', $inv2->status);
    }

    public function test_pay_without_wallet_requires_stripe(): void
    {
        $invoice = $this->createInvoice('open', 3000);

        // No wallet credit, no Stripe → will fail at Stripe API
        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', [
            'invoice_ids' => [$invoice->id],
            'use_wallet' => false,
        ]);

        // Should fail because Stripe is not configured in tests
        $response->assertStatus(422);
    }

    public function test_pay_blocked_for_other_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-2',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $otherOwner = User::factory()->create();
        $otherCompany->memberships()->create([
            'user_id' => $otherOwner->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($otherCompany);
        WalletLedger::ensureWallet($otherCompany);

        $invoice = $this->createInvoice('open', 3000);

        // ADR-432: CompanyScope makes foreign invoices invisible → 422 (not 403)
        $response = $this->actingAs($otherOwner)
            ->withHeaders(['X-Company-Id' => $otherCompany->id])
            ->postJson('/api/billing/invoices/pay', [
                'invoice_ids' => [$invoice->id],
            ]);

        $this->assertContains($response->status(), [403, 422]);
    }

    // ── Confirm endpoint ──

    public function test_confirm_requires_payment_intent_id(): void
    {
        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay/confirm', []);

        $response->assertStatus(422);
    }

    // ── Reactivation ──

    public function test_wallet_pay_reactivates_suspended_company(): void
    {
        $this->company->update(['status' => 'suspended']);
        $this->subscription->update(['status' => 'past_due']);

        $invoice = $this->createInvoice('overdue', 3000);

        WalletLedger::credit(
            company: $this->company,
            amount: 5000,
            sourceType: 'test',
            sourceId: 1,
            description: 'Test credit',
        );

        $response = $this->actAsCompany()->postJson('/api/billing/invoices/pay', [
            'invoice_ids' => [$invoice->id],
            'use_wallet' => true,
        ]);

        $response->assertOk()
            ->assertJson(['mode' => 'wallet_paid']);

        $this->company->refresh();
        $this->subscription->refresh();

        $this->assertEquals('active', $this->company->status);
        $this->assertEquals('active', $this->subscription->status);
    }
}
