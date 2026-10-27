<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-328 + bug "/company/billing/pay: factures de modules sans nom". Annexes carry addon/module
 * charges with number=NULL; they stay INDIVIDUALLY payable (own amount_due) but the outstanding
 * endpoint must expose a reliable presentation contract (display_number + business label) so the pay
 * page never shows an empty name. Amounts are NOT recomputed; void is excluded; tenants are isolated.
 */
class InvoiceOutstandingPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Pay Co', 'slug' => 'pay-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique']);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    private function invoice(array $attrs, string $lineDesc): Invoice
    {
        $inv = Invoice::create(array_merge([
            'company_id' => $this->company->id, 'status' => 'open', 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'currency' => 'EUR',
            'finalized_at' => now(), 'issued_at' => now(), 'due_at' => now()->addMonth(),
        ], $attrs));
        InvoiceLine::create(['invoice_id' => $inv->id, 'description' => $lineDesc, 'type' => 'charge', 'quantity' => 1, 'unit_amount' => 1000, 'amount' => 1000]);

        return $inv;
    }

    private function seedParentWithAnnexes(): Invoice
    {
        $parent = $this->invoice(['number' => 'INV-2026-000002', 'amount_due' => 2280, 'amount' => 2280], 'Plan Pro');
        $this->invoice(['number' => null, 'parent_invoice_id' => $parent->id, 'annexe_suffix' => 'A', 'amount_due' => 3480, 'amount' => 3480], 'Addon Planning & Pointage');
        $this->invoice(['number' => null, 'parent_invoice_id' => $parent->id, 'annexe_suffix' => 'B', 'amount_due' => 1080, 'amount' => 1080], 'Addon Documents Employés');

        return $parent;
    }

    private function actAsCompany()
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    public function test_annexes_expose_non_empty_display_number_and_business_label(): void
    {
        $this->seedParentWithAnnexes();

        $rows = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding')->assertOk()->json('invoices');
        $this->assertCount(3, $rows);

        $byDisplay = collect($rows)->keyBy('display_number');
        $this->assertTrue($byDisplay->has('INV-2026-000002'), 'parent keeps its number');
        $this->assertTrue($byDisplay->has('INV-2026-000002-A'), 'annexe A → parentNumber-A');
        $this->assertTrue($byDisplay->has('INV-2026-000002-B'), 'annexe B → parentNumber-B');

        foreach ($rows as $r) {
            $this->assertNotEmpty($r['display_number'], 'display_number is never empty/null');
            $this->assertNotEmpty($r['label'], 'business label present for every row');
        }

        $annexeA = $byDisplay->get('INV-2026-000002-A');
        $this->assertTrue($annexeA['is_annexe']);
        $this->assertSame('Addon Planning & Pointage', $annexeA['label']);
        $this->assertSame('INV-2026-000002', $annexeA['parent_number']);
    }

    public function test_amounts_are_not_recomputed_and_no_double_count(): void
    {
        $this->seedParentWithAnnexes();
        $rows = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding')->json('invoices');

        $this->assertSame(2280, collect($rows)->firstWhere('display_number', 'INV-2026-000002')['amount_due']);
        $this->assertSame(3480, collect($rows)->firstWhere('display_number', 'INV-2026-000002-A')['amount_due']);
        $this->assertSame(1080, collect($rows)->firstWhere('display_number', 'INV-2026-000002-B')['amount_due']);
        // Each row is its own balance → total is a plain sum, no parent/annexe double-count.
        $this->assertSame(2280 + 3480 + 1080, collect($rows)->sum('amount_due'));
    }

    public function test_void_invoice_is_excluded(): void
    {
        $this->seedParentWithAnnexes();
        $this->invoice(['number' => 'INV-VOID', 'status' => 'void', 'amount_due' => 0, 'voided_at' => now()], 'Voided');

        $rows = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding')->json('invoices');
        $this->assertCount(3, $rows, 'void invoice must not be payable/listed');
    }

    public function test_tenant_isolation(): void
    {
        $this->seedParentWithAnnexes();
        $other = Company::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique']);
        Invoice::create(['company_id' => $other->id, 'number' => 'INV-OTHER', 'status' => 'open', 'subtotal' => 999, 'tax_amount' => 0, 'tax_rate_bps' => 0, 'amount' => 999, 'amount_due' => 999, 'currency' => 'EUR', 'finalized_at' => now(), 'issued_at' => now(), 'due_at' => now()->addMonth()]);

        $rows = $this->actAsCompany()->getJson('/api/billing/invoices/outstanding')->json('invoices');
        $this->assertCount(3, $rows);
        $this->assertFalse(collect($rows)->contains('number', 'INV-OTHER'), 'other tenant invoice not leaked');
    }

    public function test_no_n_plus_1_on_annexe_labels(): void
    {
        $this->seedParentWithAnnexes(); // 3 invoices + lines + parent relation

        DB::enableQueryLog();
        $this->actAsCompany()->getJson('/api/billing/invoices/outstanding')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Eager-loaded (lines + parentInvoice): query count must stay bounded, not scale per-invoice.
        $this->assertLessThan(15, $count, "outstanding endpoint must not N+1 (got {$count} queries)");
    }
}
