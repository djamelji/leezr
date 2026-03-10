<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
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
 * ADR-316: Invoice PDF generation — content type, finalization guard, ownership guard.
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private Company $companyA;
    private User $ownerA;
    private Company $companyB;
    private User $ownerB;
    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        // Company A
        $this->ownerA = User::factory()->create();
        $this->companyA = Company::create([
            'name' => 'PDF Co A',
            'slug' => 'pdf-co-a',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->companyA->memberships()->create(['user_id' => $this->ownerA->id, 'role' => 'owner']);
        $this->activateCompanyModules($this->companyA);

        // Company B
        $this->ownerB = User::factory()->create();
        $this->companyB = Company::create([
            'name' => 'PDF Co B',
            'slug' => 'pdf-co-b',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->companyB->memberships()->create(['user_id' => $this->ownerB->id, 'role' => 'owner']);
        $this->activateCompanyModules($this->companyB);

        // Platform admin
        $this->admin = PlatformUser::create([
            'first_name' => 'PDF',
            'last_name' => 'Admin',
            'email' => 'pdf-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function actAsCompany(User $user, Company $company)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $company->id]);
    }

    private function createFinalizedInvoice(Company $company): Invoice
    {
        $sub = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
        ]);

        $invoice = InvoiceIssuer::createDraft($company, $sub->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Starter plan', 2900, 1);

        return InvoiceIssuer::finalize($invoice);
    }

    private function createDraftInvoice(Company $company): Invoice
    {
        $sub = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'status' => 'active',
            'provider' => 'internal',
        ]);

        $invoice = InvoiceIssuer::createDraft($company, $sub->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Starter plan', 2900, 1);

        return $invoice;
    }

    // ═══════════════════════════════════════════════════════
    // 1) Company route — PDF content type
    // ═══════════════════════════════════════════════════════

    public function test_pdf_returns_pdf_content_type(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA);

        $response = $this->actAsCompany($this->ownerA, $this->companyA)
            ->get("/api/billing/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    // ═══════════════════════════════════════════════════════
    // 2) Company route — Draft invoice returns 404
    // ═══════════════════════════════════════════════════════

    public function test_pdf_requires_finalized_invoice(): void
    {
        $invoice = $this->createDraftInvoice($this->companyA);

        $response = $this->actAsCompany($this->ownerA, $this->companyA)
            ->get("/api/billing/invoices/{$invoice->id}/pdf");

        $response->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════
    // 3) Company route — Ownership guard
    // ═══════════════════════════════════════════════════════

    public function test_pdf_ownership_guard(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA);

        // Company B user tries to access Company A's invoice
        $response = $this->actAsCompany($this->ownerB, $this->companyB)
            ->get("/api/billing/invoices/{$invoice->id}/pdf");

        $response->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════
    // 4) Platform route — PDF content type
    // ═══════════════════════════════════════════════════════

    public function test_platform_pdf_returns_pdf_content_type(): void
    {
        $invoice = $this->createFinalizedInvoice($this->companyA);

        $response = $this->actingAs($this->admin, 'platform')
            ->get("/api/platform/billing/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    // ═══════════════════════════════════════════════════════
    // 5) Platform route — Draft invoice returns 404
    // ═══════════════════════════════════════════════════════

    public function test_platform_pdf_requires_finalized_invoice(): void
    {
        $invoice = $this->createDraftInvoice($this->companyA);

        $response = $this->actingAs($this->admin, 'platform')
            ->get("/api/platform/billing/invoices/{$invoice->id}/pdf");

        $response->assertNotFound();
    }
}
