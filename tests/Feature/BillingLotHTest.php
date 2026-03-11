<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\InvoiceNumbering;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Events\ModuleEnabled;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-328 LOT H: Addon invoices as annexes of the main subscription invoice.
 */
class BillingLotHTest extends TestCase
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
            'name' => 'LotH Co',
            'slug' => 'loth-co',
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

        PlatformModule::where('key', 'logistics_tracking')->update([
            'addon_pricing' => [
                'pricing_model' => 'flat',
                'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 29],
            ],
        ]);

        // Also set pricing on a second module for multi-addon tests
        PlatformModule::where('key', 'logistics_shipments')->update([
            'addon_pricing' => [
                'pricing_model' => 'flat',
                'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 19],
            ],
        ]);
    }

    private function createMainInvoice(): Invoice
    {
        $invoice = InvoiceIssuer::createDraft(
            $this->company,
            $this->subscription->id,
            now()->subDays(10)->toDateString(),
            now()->addDays(20)->toDateString(),
        );

        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 3000, 1);

        return InvoiceIssuer::finalize($invoice);
    }

    // ── H1: Addon creates annexe when main invoice exists ──

    public function test_addon_creates_annexe_when_main_invoice_exists(): void
    {
        $mainInvoice = $this->createMainInvoice();

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $annexe = Invoice::where('company_id', $this->company->id)
            ->where('parent_invoice_id', $mainInvoice->id)
            ->first();

        $this->assertNotNull($annexe, 'Addon should create an annexe invoice');
        $this->assertEquals('A', $annexe->annexe_suffix);
        $this->assertTrue($annexe->isAnnexe());
        $this->assertEquals($this->subscription->id, $annexe->subscription_id);
        $this->assertNotNull($annexe->finalized_at);
    }

    // ── H2: Addon creates standalone when no main invoice ──

    public function test_addon_creates_standalone_when_no_main_invoice(): void
    {
        // No main invoice created — addon activated before first renewal
        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $invoice = Invoice::where('company_id', $this->company->id)
            ->whereNotNull('finalized_at')
            ->first();

        $this->assertNotNull($invoice);
        $this->assertNull($invoice->parent_invoice_id, 'Should be standalone');
        $this->assertNull($invoice->annexe_suffix);
        $this->assertFalse($invoice->isAnnexe());
    }

    // ── H3: Annexe suffix increments A, B, C ──

    public function test_annexe_suffix_increments_a_b_c(): void
    {
        $mainInvoice = $this->createMainInvoice();

        $this->assertEquals('A', InvoiceNumbering::nextAnnexeSuffix($mainInvoice));

        // Create first annexe
        Invoice::create([
            'parent_invoice_id' => $mainInvoice->id,
            'annexe_suffix' => 'A',
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'currency' => 'EUR',
            'status' => 'draft',
            'amount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'wallet_credit_applied' => 0,
            'amount_due' => 0,
        ]);

        $this->assertEquals('B', InvoiceNumbering::nextAnnexeSuffix($mainInvoice));

        // Create second annexe
        Invoice::create([
            'parent_invoice_id' => $mainInvoice->id,
            'annexe_suffix' => 'B',
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'currency' => 'EUR',
            'status' => 'draft',
            'amount' => 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'wallet_credit_applied' => 0,
            'amount_due' => 0,
        ]);

        $this->assertEquals('C', InvoiceNumbering::nextAnnexeSuffix($mainInvoice));
    }

    // ── H4: Annexe display number format ──

    public function test_annexe_display_number_format(): void
    {
        $mainInvoice = $this->createMainInvoice();

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $annexe = Invoice::where('parent_invoice_id', $mainInvoice->id)->first();

        $this->assertNotNull($annexe);
        $this->assertEquals($mainInvoice->number . '-A', $annexe->displayNumber());
    }

    // ── H5: Parent stays immutable after annexe ──

    public function test_parent_stays_immutable_after_annexe(): void
    {
        $mainInvoice = $this->createMainInvoice();
        $originalAmount = $mainInvoice->amount;
        $originalNumber = $mainInvoice->number;

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $mainInvoice->refresh();
        $this->assertEquals($originalAmount, $mainInvoice->amount);
        $this->assertEquals($originalNumber, $mainInvoice->number);
        $this->assertNotNull($mainInvoice->finalized_at);
    }

    // ── H6: Multiple addons get distinct suffixes ──

    public function test_multiple_addons_distinct_suffixes(): void
    {
        $mainInvoice = $this->createMainInvoice();

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');
        ModuleEnabled::dispatch($this->company, 'logistics_shipments');

        $annexes = Invoice::where('parent_invoice_id', $mainInvoice->id)
            ->orderBy('annexe_suffix')
            ->get();

        $this->assertCount(2, $annexes);
        $this->assertEquals('A', $annexes[0]->annexe_suffix);
        $this->assertEquals('B', $annexes[1]->annexe_suffix);
    }

    // ── H7: Annexe does not consume global sequence ──

    public function test_annexe_not_consume_global_sequence(): void
    {
        $mainInvoice = $this->createMainInvoice();
        $mainNumber = $mainInvoice->number; // e.g. INV-2026-000001

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        // Create another standalone invoice — should get the next number
        $nextInvoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($nextInvoice, 'plan', 'test', 1000, 1);
        $nextInvoice = InvoiceIssuer::finalize($nextInvoice);

        // Extract sequence numbers
        preg_match('/(\d+)$/', $mainNumber, $mainSeq);
        preg_match('/(\d+)$/', $nextInvoice->number, $nextSeq);

        $this->assertEquals((int) $mainSeq[1] + 1, (int) $nextSeq[1],
            'Annexe should not have consumed a sequence number');
    }

    // ── H8: Invoice detail API includes annexe data ──

    public function test_invoice_detail_includes_annexe_data(): void
    {
        $mainInvoice = $this->createMainInvoice();

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/billing/invoices/{$mainInvoice->id}");

        $response->assertOk();
        $data = $response->json('invoice');

        $this->assertArrayHasKey('annexes', $data);
        $this->assertCount(1, $data['annexes']);
        $this->assertStringEndsWith('-A', $data['annexes'][0]['display_number']);
    }
}
