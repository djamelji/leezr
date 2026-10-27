<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoveredInterval;
use App\Core\Billing\BillingWatermark;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\Migration\BillingV2MigrationSimulator;
use App\Core\Billing\Subscription;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-632 (M10-A) — simulateur de migration V2 en lecture seule : reconstitution de couverture,
 * classification éligible/revue manuelle, et surtout AUCUNE écriture.
 */
class BillingV2MigrationSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        JobdomainRegistry::sync();
        Plan::updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_monthly' => 2900, 'price_yearly' => 29000, 'is_active' => true]);
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Mig Co', 'slug' => 'mig-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c, string $source = 'subscribed', string $start = '2026-11-01 00:00:00', string $end = '2026-12-01 00:00:00'): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => 1, 'plan_price_cents' => 2900, 'plan_price_source' => $source,
            'current_period_start' => $start, 'current_period_end' => $end,
        ]);
    }

    private function v1(Company $c, Subscription $s, ?int $parent = null): Invoice
    {
        return Invoice::create([
            'company_id' => $c->id, 'subscription_id' => $parent ? null : $s->id, 'parent_invoice_id' => $parent,
            'billing_model_version' => 1, 'number' => $parent ? null : 'INV-'.uniqid(), 'subtotal' => 2900, 'tax_amount' => 580,
            'tax_rate_bps' => 2000, 'amount' => 3480, 'amount_due' => 3480, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
        ]);
    }

    private function line(Invoice $inv, string $type, ?string $mod, string $ps, string $pe): void
    {
        InvoiceLine::create([
            'invoice_id' => $inv->id, 'type' => $type, 'module_key' => $mod, 'description' => $type,
            'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900, 'period_start' => $ps, 'period_end' => $pe,
        ]);
    }

    public function test_reliable_subscription_with_clean_coverage_is_eligible(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $inv = $this->v1($c, $s);
        $this->line($inv, 'plan', null, '2026-10-01', '2026-11-01'); // couvre octobre

        $r = BillingV2MigrationSimulator::simulateSubscription($s);
        $this->assertSame('eligible', $r['classification']);
        $wm = collect($r['proposed_watermarks'])->firstWhere('prestation_key', 'plan');
        $this->assertNotNull($wm['billed_through'], 'plan couvert → watermark connu');
        $this->assertFalse($wm['unknown']);
    }

    public function test_module_billed_full_month_from_activation_is_tracked_independently(): void
    {
        // Module activé le 16, facturé mois plein depuis activation (16 → 16 du mois suivant), NON aligné
        // sur le cycle plan (1er → 1er). Sa couverture doit être reconstruite sur SA période propre.
        $c = $this->company();
        $s = $this->sub($c);
        CompanyAddonSubscription::create(['company_id' => $c->id, 'module_key' => 'workforce_docs', 'amount_cents' => 900, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => '2026-10-16 00:00:00', 'deactivated_at' => null]);
        $inv = $this->v1($c, $s);
        $this->line($inv, 'plan', null, '2026-10-01', '2026-11-01');
        $this->line($inv, 'addon', 'workforce_docs', '2026-10-16', '2026-11-16'); // mois plein, désaligné

        $r = BillingV2MigrationSimulator::simulateSubscription($s);
        $cov = collect($r['proposed_coverage'])->where('prestation_key', 'workforce_docs')->first();
        $this->assertNotNull($cov, 'couverture module reconstruite sur sa propre période');
        $this->assertStringStartsWith('2026-10-16', $cov['period_start']);
        $this->assertStringStartsWith('2026-11-16', $cov['period_end']);
        $wm = collect($r['proposed_watermarks'])->firstWhere('prestation_key', 'workforce_docs');
        $this->assertStringStartsWith('2026-11-16', $wm['billed_through'], 'watermark module = fin de SA période, pas du cycle plan');
    }

    public function test_provisional_price_forces_manual_review(): void
    {
        $c = $this->company();
        $s = $this->sub($c, 'provisional');
        $inv = $this->v1($c, $s);
        $this->line($inv, 'plan', null, '2026-10-01', '2026-11-01');

        $r = BillingV2MigrationSimulator::simulateSubscription($s);
        $this->assertSame('manual_review', $r['classification']);
        $this->assertContains('provisional_or_unreliable_price', $r['block_reasons']);
    }

    public function test_ambiguous_current_period_forces_manual_review(): void
    {
        // Fin de période à 14:32 ≠ début (00:00) → sémantique inconnue → non résolu → revue manuelle.
        $c = $this->company();
        $s = $this->sub($c, 'subscribed', '2026-11-01 00:00:00', '2026-12-01 14:32:00');
        $inv = $this->v1($c, $s);
        $this->line($inv, 'plan', null, '2026-10-01', '2026-11-01');

        $r = BillingV2MigrationSimulator::simulateSubscription($s);
        $this->assertSame('manual_review', $r['classification']);
        $this->assertContains('ambiguous_current_period', $r['block_reasons']);
    }

    public function test_overlapping_v1_coverage_is_flagged(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $inv1 = $this->v1($c, $s);
        $this->line($inv1, 'plan', null, '2026-10-01', '2026-11-01');
        $inv2 = $this->v1($c, $s);
        $this->line($inv2, 'plan', null, '2026-10-15', '2026-11-15'); // chevauche le précédent

        $r = BillingV2MigrationSimulator::simulateSubscription($s);
        $this->assertTrue($r['overlap_risk']);
        $this->assertSame('manual_review', $r['classification']);
        $this->assertContains('coverage_overlap', $r['block_reasons']);
    }

    public function test_simulation_writes_nothing(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $inv = $this->v1($c, $s);
        $this->line($inv, 'plan', null, '2026-10-01', '2026-11-01');
        CompanyAddonSubscription::create(['company_id' => $c->id, 'module_key' => 'm1', 'amount_cents' => 100, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now(), 'deactivated_at' => null]);

        BillingV2MigrationSimulator::simulateAll();

        $this->assertSame(0, BillingWatermark::count(), 'aucun watermark écrit');
        $this->assertSame(0, BillingCoveredInterval::count(), 'aucun intervalle écrit');
        $this->assertSame(1, Invoice::count(), 'factures inchangées');
    }

    public function test_void_invoices_do_not_count_as_coverage(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $inv = $this->v1($c, $s);
        $inv->update(['status' => 'void']);
        $this->line($inv, 'plan', null, '2026-10-01', '2026-11-01');

        $r = BillingV2MigrationSimulator::simulateSubscription($s);
        $this->assertCount(0, $r['proposed_coverage'], 'une facture annulée ne couvre rien');
    }
}
