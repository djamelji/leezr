<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * Phase A — Billing Read API tests.
 *
 * Tests:
 *   1. Company isolation: cannot fetch another company's invoice
 *   2. Company invoices: paginated list with real data
 *   3. Company invoice detail: includes lines
 *   4. Company wallet: balance + transactions
 *   5. Company overview: aggregate data
 *   6. Company subscription: enhanced fields
 *   7. Platform invoice list: filters by company/status
 *   8. Platform invoice detail: cross-company visibility
 *   9. Platform dunning: lists overdue/uncollectible
 *  10. Snapshot immutability: finalized invoice data frozen
 *  11. Platform unauthenticated: blocked
 *  12. Company unauthenticated: blocked
 */
class BillingReadApiTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private User $ownerA;
    private Company $companyA;
    private User $ownerB;
    private Company $companyB;
    private Subscription $subscriptionA;
    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        // Company A
        $this->ownerA = User::factory()->create();
        $this->companyA = Company::create([
            'name' => 'Company A',
            'slug' => 'company-a',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->companyA->memberships()->create([
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($this->companyA);

        $this->subscriptionA = Subscription::create([
            'company_id' => $this->companyA->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        // Company B (for isolation tests)
        $this->ownerB = User::factory()->create();
        $this->companyB = Company::create([
            'name' => 'Company B',
            'slug' => 'company-b',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->companyB->memberships()->create([
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($this->companyB);

        // Platform admin
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    private function actAsCompanyA(): static
    {
        return $this->actingAs($this->ownerA)->withHeaders(['X-Company-Id' => $this->companyA->id]);
    }

    private function actAsCompanyB(): static
    {
        return $this->actingAs($this->ownerB)->withHeaders(['X-Company-Id' => $this->companyB->id]);
    }

    private function actAsPlatform(): static
    {
        return $this->actingAs($this->platformAdmin, 'platform');
    }

    private function createFinalizedInvoice(Company $company, ?int $subscriptionId = null, int $amount = 2900): Invoice
    {
        $invoice = InvoiceIssuer::createDraft($company, $subscriptionId);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', $amount);

        return InvoiceIssuer::finalize($invoice);
    }

    // ── 1. Company isolation: cannot fetch another company's invoice ──

    public function test_company_cannot_fetch_other_company_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);

        // Company B tries to access Company A's invoice
        $response = $this->actAsCompanyB()
            ->getJson("/api/billing/invoices/{$invoice->id}");

        $response->assertStatus(404);
    }

    // ── 2. Company invoices: paginated list ──

    public function test_company_invoices_returns_paginated_list(): void
    {
        $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id, 2900);
        $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id, 1500);

        $response = $this->actAsCompanyA()
            ->getJson('/api/billing/invoices');

        $response->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'per_page', 'total'])
            ->assertJsonPath('total', 2);
    }

    // ── 3. Company invoice detail: includes lines ──

    public function test_company_invoice_detail_includes_lines(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);

        $response = $this->actAsCompanyA()
            ->getJson("/api/billing/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'invoice' => [
                    'id', 'number', 'status', 'amount', 'subtotal',
                    'tax_amount', 'amount_due', 'lines',
                ],
            ])
            ->assertJsonPath('invoice.id', $invoice->id)
            ->assertJsonCount(1, 'invoice.lines');
    }

    // ── 5. Company overview: aggregate data ──

    public function test_company_overview_returns_aggregate(): void
    {
        $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id, 2900);

        $response = $this->actAsCompanyA()
            ->getJson('/api/billing/overview');

        $response->assertOk()
            ->assertJsonStructure([
                'subscription', 'wallet_balance', 'outstanding_invoices',
                'outstanding_amount', 'currency',
            ]);
    }

    // ── 6. Company subscription: enhanced fields ──

    public function test_company_subscription_returns_enhanced_fields(): void
    {
        $response = $this->actAsCompanyA()
            ->getJson('/api/billing/subscription');

        $response->assertOk()
            ->assertJsonStructure([
                'subscription' => [
                    'id', 'plan_key', 'interval', 'status',
                    'current_period_start', 'current_period_end',
                    'trial_ends_at', 'cancel_at_period_end',
                ],
            ])
            ->assertJsonPath('subscription.plan_key', 'pro')
            ->assertJsonPath('subscription.interval', 'monthly')
            ->assertJsonPath('subscription.cancel_at_period_end', false);
    }

    // ── 7. Platform invoice list with filters ──

    public function test_platform_invoices_with_company_filter(): void
    {
        $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);
        $this->createFinalizedInvoice($this->companyB);

        // Filter by company A
        $response = $this->actAsPlatform()
            ->getJson("/api/platform/billing/invoices?company_id={$this->companyA->id}");

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    // ── 8. Platform invoice detail: cross-company visibility ──

    public function test_platform_invoice_detail_cross_company(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);

        $response = $this->actAsPlatform()
            ->getJson("/api/platform/billing/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'invoice' => [
                    'id', 'number', 'company', 'status',
                    'lines', 'credit_notes', 'billing_snapshot',
                ],
            ])
            ->assertJsonPath('invoice.company.name', 'Company A');
    }

    // ── 9. Platform dunning: lists overdue/uncollectible ──

    public function test_platform_dunning_lists_overdue_invoices(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);
        $invoice->update(['status' => 'overdue', 'next_retry_at' => now()->addDay()]);

        $response = $this->actAsPlatform()
            ->getJson('/api/platform/billing/dunning');

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    // ── 10. Snapshot immutability: finalized invoice number is frozen ──

    public function test_finalized_invoice_snapshot_is_immutable(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);

        $originalNumber = $invoice->number;
        $originalAmount = $invoice->amount;

        // Re-fetch through API
        $response = $this->actAsCompanyA()
            ->getJson("/api/billing/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('invoice.number', $originalNumber)
            ->assertJsonPath('invoice.amount', $originalAmount);

        // Verify DB matches
        $invoice->refresh();
        $this->assertEquals($originalNumber, $invoice->number);
        $this->assertEquals($originalAmount, $invoice->amount);
    }

    // ── 11. Platform unauthenticated: blocked ──

    public function test_platform_billing_unauthenticated_blocked(): void
    {
        $this->getJson('/api/platform/billing/invoices')
            ->assertStatus(401);
    }

    // ── 12. Company unauthenticated: blocked ──

    public function test_company_billing_unauthenticated_blocked(): void
    {
        $this->getJson('/api/billing/invoices')
            ->assertStatus(401);
    }

    // ── 13. Invoice status filter works ──

    public function test_company_invoices_filter_by_status(): void
    {
        $invoice1 = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id, 2900);
        $invoice2 = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id, 1500);
        $invoice2->update(['status' => 'paid', 'paid_at' => now()]);

        $response = $this->actAsCompanyA()
            ->getJson('/api/billing/invoices?status=paid');

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    // ── 14. Platform subscriptions list ──

    public function test_platform_subscriptions_list(): void
    {
        $response = $this->actAsPlatform()
            ->getJson('/api/platform/billing/all-subscriptions');

        $response->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    // ── 16. Invoice PDF: returns PDF for own invoice ──

    public function test_invoice_pdf_returns_pdf_for_own_invoice(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);

        $response = $this->actAsCompanyA()
            ->get("/api/billing/invoices/{$invoice->id}/pdf");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ── 17. Invoice PDF: 404 for other company's invoice ──

    public function test_invoice_pdf_blocked_for_other_company(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA, $this->subscriptionA->id);

        $response = $this->actAsCompanyB()
            ->get("/api/billing/invoices/{$invoice->id}/pdf");

        $response->assertStatus(404);
    }

    // ── 18. Invoice PDF: 404 for draft (non-finalized) invoice ──

    public function test_invoice_pdf_blocked_for_draft_invoice(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->companyA->id,
            'subscription_id' => $this->subscriptionA->id,
            'number' => 'DRAFT-001',
            'amount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'wallet_credit_applied' => 0,
            'amount_due' => 0,
            'currency' => 'EUR',
            'status' => 'draft',
        ]);

        $response = $this->actAsCompanyA()
            ->get("/api/billing/invoices/{$invoice->id}/pdf");

        $response->assertStatus(404);
    }

    // ── 19. Invoice PDF: unauthenticated blocked ──

    public function test_invoice_pdf_unauthenticated_blocked(): void
    {
        $this->getJson('/api/billing/invoices/1/pdf')
            ->assertStatus(401);
    }
}
