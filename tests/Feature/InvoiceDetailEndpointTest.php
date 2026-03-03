<?php

namespace Tests\Feature;

use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\PaymentRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * ADR-145: Invoice detail endpoint tests.
 *
 * Covers platform + company invoice detail + PDF endpoints.
 */
class InvoiceDetailEndpointTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private PlatformUser $platformAdmin;
    private User $owner;
    private Company $company;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        // Platform admin with view_billing
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        // Company + owner
        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Invoice Co',
            'slug' => 'invoice-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->activateCompanyModules($this->company);

        // Finalized invoice with line
        $this->invoice = Invoice::create([
            'company_id' => $this->company->id,
            'number' => 'INV-TEST-001',
            'amount' => 5000,
            'subtotal' => 4200,
            'tax_amount' => 800,
            'tax_rate_bps' => 2000,
            'wallet_credit_applied' => 0,
            'amount_due' => 5000,
            'currency' => 'EUR',
            'status' => 'open',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issued_at' => now(),
            'due_at' => now()->addDays(30),
            'finalized_at' => now(),
        ]);

        InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'type' => 'subscription',
            'description' => 'Pro Plan — Monthly',
            'quantity' => 1,
            'unit_amount' => 4200,
            'amount' => 4200,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // Platform invoice detail
    // ═══════════════════════════════════════════════════════

    public function test_platform_invoice_detail_returns_full_data(): void
    {
        // Add payment and ledger entry
        Payment::create([
            'invoice_id' => $this->invoice->id,
            'company_id' => $this->company->id,
            'amount' => 5000,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'internal',
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'correlation_id' => (string) $this->invoice->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'AR',
            'debit' => 50.00,
            'credit' => 0.00,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => $this->invoice->id,
            'recorded_at' => now(),
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/billing/invoices/{$this->invoice->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'invoice' => [
                    'id', 'number', 'company', 'status', 'amount', 'subtotal',
                    'tax_amount', 'tax_rate_bps', 'currency',
                    'lines', 'credit_notes', 'payments', 'ledger_entries',
                ],
            ]);

        $invoice = $response->json('invoice');

        $this->assertCount(1, $invoice['lines']);
        $this->assertCount(1, $invoice['payments']);
        $this->assertCount(1, $invoice['ledger_entries']);
        $this->assertEquals('INV-TEST-001', $invoice['number']);
        $this->assertEquals('EUR', $invoice['currency']);

        // Ledger entry structure
        $ledger = $invoice['ledger_entries'][0];
        $this->assertEquals('invoice_issued', $ledger['entry_type']);
        $this->assertEquals('AR', $ledger['account_code']);
    }

    public function test_platform_invoice_detail_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/invoices/99999');

        $response->assertStatus(404);
    }

    public function test_platform_invoice_detail_requires_auth(): void
    {
        $response = $this->getJson("/api/platform/billing/invoices/{$this->invoice->id}");

        $response->assertStatus(401);
    }

    public function test_platform_invoice_detail_requires_view_billing(): void
    {
        $viewer = PlatformUser::create([
            'first_name' => 'Viewer',
            'last_name' => 'NoPerms',
            'email' => 'viewer@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        // No roles attached — no view_billing permission

        $response = $this->actingAs($viewer, 'platform')
            ->getJson("/api/platform/billing/invoices/{$this->invoice->id}");

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Company invoice detail
    // ═══════════════════════════════════════════════════════

    public function test_company_invoice_detail_returns_full_data(): void
    {
        Payment::create([
            'invoice_id' => $this->invoice->id,
            'company_id' => $this->company->id,
            'amount' => 5000,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'internal',
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/billing/invoices/{$this->invoice->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'invoice' => [
                    'id', 'number', 'status', 'amount', 'subtotal',
                    'lines', 'credit_notes', 'payments', 'ledger_entries',
                ],
            ]);

        $invoice = $response->json('invoice');

        $this->assertCount(1, $invoice['lines']);
        $this->assertCount(1, $invoice['payments']);
    }

    public function test_company_invoice_detail_404_for_other_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $otherOwner = User::factory()->create();

        $otherCompany->memberships()->create([
            'user_id' => $otherOwner->id,
            'role' => 'owner',
        ]);

        $this->activateCompanyModules($otherCompany);

        // Try to access invoice from company A using company B context
        $response = $this->actingAs($otherOwner)
            ->withHeaders(['X-Company-Id' => $otherCompany->id])
            ->getJson("/api/billing/invoices/{$this->invoice->id}");

        $response->assertStatus(404);
    }

    public function test_company_invoice_detail_requires_company_header(): void
    {
        // No X-Company-Id header — middleware returns 400
        $response = $this->actingAs($this->owner)
            ->getJson("/api/billing/invoices/{$this->invoice->id}");

        $response->assertStatus(400);
    }

    // ═══════════════════════════════════════════════════════
    // Company PDF endpoint
    // ═══════════════════════════════════════════════════════

    public function test_company_pdf_returns_html(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->get("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('content-type'));
    }

    public function test_company_pdf_requires_company_header(): void
    {
        // No X-Company-Id header — middleware returns 400
        $response = $this->actingAs($this->owner)
            ->get("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(400);
    }

    public function test_company_pdf_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->get('/api/billing/invoices/99999/pdf');

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════
    // D4d Hardening — numeric formatting + empty ledger
    // ═══════════════════════════════════════════════════════

    public function test_ledger_entries_return_numeric_debit_credit(): void
    {
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'correlation_id' => (string) $this->invoice->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'AR',
            'debit' => 50.00,
            'credit' => 0.00,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => $this->invoice->id,
            'recorded_at' => now(),
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/billing/invoices/{$this->invoice->id}");

        $response->assertOk();

        $ledger = $response->json('invoice.ledger_entries.0');

        // Laravel decimal:2 cast returns strings in JSON — frontend must handle both
        $this->assertNotNull($ledger['debit']);
        $this->assertNotNull($ledger['credit']);
        $this->assertEquals(50.00, (float) $ledger['debit']);
        $this->assertEquals(0.00, (float) $ledger['credit']);
    }

    public function test_invoice_detail_without_ledger_entries(): void
    {
        // Invoice with no ledger entries — should still return valid response
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/billing/invoices/{$this->invoice->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'invoice' => ['ledger_entries'],
            ]);

        $this->assertCount(0, $response->json('invoice.ledger_entries'));
    }
}
