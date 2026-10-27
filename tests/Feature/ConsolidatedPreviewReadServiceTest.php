<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\ReadModels\ConsolidatedPreviewReadService;
use App\Core\Billing\Subscription;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-632 — aperçu consolidé V2 (read-only). Montants issus des factures persistées (aucun recalcul),
 * provisional hors total, aucun doublon parent/annexe, isolation multi-tenant, aucune écriture.
 */
class ConsolidatedPreviewReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        JobdomainRegistry::sync();
        Plan::updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_monthly' => 2900, 'price_yearly' => 29000, 'is_active' => true]);
    }

    private function company(string $name = 'Prev Co'): Company
    {
        return Company::create(['name' => $name, 'slug' => 'prev-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function provisionalSub(Company $c): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'provisional',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now(),
        ]);
    }

    private function inv(Company $c, ?int $parent, ?string $suffix, int $ht, int $tax, ?int $subId = null, string $cur = 'EUR'): Invoice
    {
        return Invoice::create([
            'company_id' => $c->id, 'subscription_id' => $parent ? null : $subId, 'parent_invoice_id' => $parent, 'annexe_suffix' => $suffix,
            'number' => $parent ? null : 'INV-'.uniqid(), 'subtotal' => $ht, 'tax_amount' => $tax, 'tax_rate_bps' => 2000,
            'amount' => $ht + $tax, 'amount_due' => $ht + $tax, 'wallet_credit_applied' => 0, 'currency' => $cur,
            'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
        ]);
    }

    private function line(Invoice $i, string $type, ?string $mod, int $amount, $ps = null, $pe = null): void
    {
        InvoiceLine::create(['invoice_id' => $i->id, 'type' => $type, 'module_key' => $mod, 'description' => $mod ?? $type,
            'quantity' => 1, 'unit_amount' => $amount, 'amount' => $amount, 'period_start' => $ps, 'period_end' => $pe]);
    }

    /** Reproduit exactement Leezr Logistics PROD : 3 modules, aucune période, Pro provisional non facturé. */
    private function leezrMirror(Company $c): void
    {
        $sub = $this->provisionalSub($c);
        $p = $this->inv($c, null, null, 1900, 380, $sub->id);
        $this->line($p, 'addon', 'workforce_leave', 1900);
        $a = $this->inv($c, $p->id, 'A', 2900, 580);
        $this->line($a, 'addon', 'workforce_planning', 2900);
        $b = $this->inv($c, $p->id, 'B', 900, 180);
        $this->line($b, 'addon', 'workforce_documents', 900);
    }

    public function test_totals_match_persisted_amounts_no_recompute(): void
    {
        $c = $this->company();
        $this->leezrMirror($c);
        $p = ConsolidatedPreviewReadService::forCompany($c);

        // 57,00 € / 11,40 € / 68,40 € — exactement les montants persistés.
        $this->assertSame(5700, $p['existing_total']['ht_cents']);
        $this->assertSame(1140, $p['existing_total']['tax_cents']);
        $this->assertSame(6840, $p['existing_total']['ttc_cents']);
        $this->assertSame(6840, $p['existing_total']['due_cents']);
    }

    public function test_three_prestations_grouped_no_parent_annexe_duplication(): void
    {
        $c = $this->company();
        $this->leezrMirror($c);
        $p = ConsolidatedPreviewReadService::forCompany($c);

        $this->assertSame(3, $p['invoice_count']);
        $modules = collect($p['sections'])->firstWhere('key', 'modules');
        $this->assertCount(3, $modules['rows'], '3 prestations, aucune dédoublée');
        $invoiceIds = collect($modules['rows'])->pluck('invoice_id');
        $this->assertSame($invoiceIds->unique()->count(), $invoiceIds->count(), 'pas de doublon parent/annexe');
    }

    public function test_provisional_plan_excluded_from_total_shown_separately(): void
    {
        $c = $this->company();
        $this->leezrMirror($c);
        $p = ConsolidatedPreviewReadService::forCompany($c);

        // Pro 29 € provisional : présent en simulation, JAMAIS dans le total des créances.
        $this->assertCount(1, $p['provisional_simulation']);
        $this->assertSame(2900, $p['provisional_simulation'][0]['ht_cents_indicative']);
        $this->assertSame('provisional', $p['provisional_simulation'][0]['price_source']);
        $this->assertSame(5700, $p['existing_total']['ht_cents'], 'le provisional (2900) n’est PAS ajouté');
    }

    public function test_missing_periods_flagged_not_fabricated(): void
    {
        $c = $this->company();
        $this->leezrMirror($c); // lignes sans période
        $p = ConsolidatedPreviewReadService::forCompany($c);

        foreach ($p['sections'] as $s) {
            foreach ($s['rows'] as $row) {
                $this->assertFalse($row['period_known']);
                $this->assertNull($row['period_start']);
            }
        }
    }

    public function test_known_period_preserved_when_present(): void
    {
        $c = $this->company();
        $sub = $this->provisionalSub($c);
        $i = $this->inv($c, null, null, 1900, 380, $sub->id);
        $this->line($i, 'addon', 'workforce_leave', 1900, '2026-10-01', '2026-11-01');
        $p = ConsolidatedPreviewReadService::forCompany($c);
        $row = $p['sections'][0]['rows'][0];
        $this->assertTrue($row['period_known']);
        $this->assertSame('2026-10-01', $row['period_start']);
    }

    public function test_tenant_isolation(): void
    {
        $a = $this->company('A');
        $this->leezrMirror($a);
        $b = $this->company('B'); // vide
        $pb = ConsolidatedPreviewReadService::forCompany($b);
        $this->assertSame(0, $pb['invoice_count']);
        $this->assertSame(0, $pb['existing_total']['ht_cents'], 'aucune donnée d’un autre tenant');
    }

    public function test_void_invoice_excluded(): void
    {
        $c = $this->company();
        $sub = $this->provisionalSub($c);
        $i = $this->inv($c, null, null, 1900, 380, $sub->id);
        $this->line($i, 'addon', 'workforce_leave', 1900);
        $i->update(['status' => 'void']);
        $p = ConsolidatedPreviewReadService::forCompany($c);
        $this->assertSame(0, $p['existing_total']['ht_cents']);
    }

    public function test_reliable_plan_not_shown_as_simulation(): void
    {
        $c = $this->company();
        Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now(),
        ]);
        $p = ConsolidatedPreviewReadService::forCompany($c);
        $this->assertSame([], $p['provisional_simulation'], 'prix fiable → pas de simulation');
    }

    public function test_read_only_no_writes(): void
    {
        $c = $this->company();
        $this->leezrMirror($c);
        $invBefore = Invoice::count();
        $lineBefore = InvoiceLine::count();
        ConsolidatedPreviewReadService::forCompany($c);
        $this->assertSame($invBefore, Invoice::count());
        $this->assertSame($lineBefore, InvoiceLine::count());
    }
}
