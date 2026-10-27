<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-632 (M0) — strictement additif : versionnement `billing_model_version` (défaut 1) + table
 * `billable_events` vide et non branchée. Aucun changement de comportement : tout invoice créé par les
 * chemins actuels reste `1`, aucun événement facturable n'est écrit.
 */
class BillingModelVersionM0Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
    }

    public function test_billable_events_table_exists_and_is_empty(): void
    {
        $this->assertTrue(Schema::hasTable('billable_events'));
        $this->assertSame(0, \DB::table('billable_events')->count(), 'table créée VIDE et non branchée');
    }

    public function test_billing_model_version_column_exists_with_default_1(): void
    {
        $this->assertTrue(Schema::hasColumn('invoices', 'billing_model_version'));

        $company = Company::create(['name' => 'M0 Co', 'slug' => 'm0-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);

        // Création par le chemin standard, sans préciser la version → défaut DB = 1 (v1 ADR-328).
        $inv = Invoice::create([
            'company_id' => $company->id, 'number' => 'INV-M0-1', 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
        ]);

        $this->assertSame(1, (int) $inv->fresh()->billing_model_version, 'les chemins actuels produisent v1');
    }

    public function test_no_existing_invoice_is_v2(): void
    {
        $company = Company::create(['name' => 'M0 Co2', 'slug' => 'm0b-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
        Invoice::create([
            'company_id' => $company->id, 'number' => 'INV-M0-2', 'subtotal' => 500, 'tax_amount' => 100,
            'tax_rate_bps' => 2000, 'amount' => 600, 'amount_due' => 600, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
        ]);

        $this->assertSame(0, Invoice::where('billing_model_version', 2)->count(), 'aucune facture V2 créée en M0');
    }
}
