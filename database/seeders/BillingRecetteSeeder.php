<?php

namespace Database\Seeders;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Billing 3.0 deterministic recette fixtures (ADR-614). LOCAL/DEV only — never production.
 *
 * Reproduces the real business states needed to audit/test every Billing screen WITHOUT touching
 * production financial data:
 *  - active subscription with an overdue period + provisional (unreliable) contracted price;
 *  - flat + per-seat addons; a parent invoice + annexes; a not-yet-due invoice AND an overdue one;
 *  - a succeeded payment and a failed payment;
 *  - a FR company (domestic VAT) and a cross-border case for the tax matrix.
 */
class BillingRecetteSeeder extends Seeder
{
    public function run(): void
    {
        if (App::environment('production')) {
            $this->command?->warn('BillingRecetteSeeder skipped on production.');

            return;
        }

        $this->seedOverdueProvisionalCase();      // reproduces Leezr Logistics
        $this->seedHealthyPaidCase();             // confirmed price, paid, card on file
        $this->seedOverdueFailedPaymentCase();    // genuine payment problem
        $this->seedOwnerCompanyInvoice();         // a finalized invoice on the owner persona's company (browser recette)
    }

    /**
     * Attach a finalized invoice (plan + addon lines, one recorded payment) to the company owned by
     * the owner persona, so the owner invoice-detail + PDF browser journey can be tested on recette
     * data. Idempotent: wipes only the [RECETTE]-numbered invoice it owns.
     */
    private function seedOwnerCompanyInvoice(): void
    {
        $user = \App\Core\Models\User::where('email', 'owner@leezr.test')->first();
        $company = $user?->memberships()->where('role', 'owner')->first()?->company
            ?? Company::where('name', 'Leezr Logistics')->first();
        if (! $company) {
            return;
        }

        Invoice::where('company_id', $company->id)->where('number', 'INV-RECETTE-OWNER')->get()
            ->each(function ($i) {
                InvoiceLine::where('invoice_id', $i->id)->delete();
                Payment::where('invoice_id', $i->id)->delete();
                $i->delete();
            });

        $inv = $this->invoice($company, 'INV-RECETTE-OWNER', null, null, 4800, 960, now()->addMonth(), 'open');
        $this->line($inv, 'plan', null, 'Abonnement Pro', 2900);
        $this->line($inv, 'addon', 'workforce_leave', 'Addon Congés & Absences', 1900);
    }

    /** Company A — active Pro, period ended, provisional price, 3 flat addons (1 invoice + 2 annexes), not due. */
    private function seedOverdueProvisionalCase(): void
    {
        $c = Company::firstOrCreate(['name' => '[RECETTE] Overdue Provisional'], ['slug' => uniqid('recette-'), 'market_key' => 'FR', 'address_country_code' => 'FR', 'jobdomain_key' => 'logistique']);
        $this->wipe($c);

        Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'provisional',
            'current_period_start' => now()->subMonths(5)->startOfMonth(),
            'current_period_end' => now()->subMonths(5)->endOfMonth(),
        ]);

        foreach ([['workforce_leave', 1900], ['workforce_planning', 2900], ['workforce_documents', 900]] as [$key, $cents]) {
            CompanyAddonSubscription::updateOrCreate(
                ['company_id' => $c->id, 'module_key' => $key],
                ['amount_cents' => $cents, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()->subDays(3), 'deactivated_at' => null],
            );
        }

        // Parent invoice + 2 annexes, all open, due in the FUTURE (not yet due).
        $parent = $this->invoice($c, 'INV-RECETTE-0001', null, null, 1900, 380, now()->addMonth());
        $this->line($parent, 'addon', 'workforce_leave', 'Addon Congés & Absences', 1900);
        $a = $this->invoice($c, null, $parent->id, 'A', 2900, 580, now()->addMonth());
        $this->line($a, 'addon', 'workforce_planning', 'Addon Planning & Pointage', 2900);
        $b = $this->invoice($c, null, $parent->id, 'B', 900, 180, now()->addMonth());
        $this->line($b, 'addon', 'workforce_documents', 'Addon Documents Employés', 900);
    }

    /** Company B — confirmed price, invoice paid, nothing outstanding. */
    private function seedHealthyPaidCase(): void
    {
        $c = Company::firstOrCreate(['name' => '[RECETTE] Healthy Paid'], ['slug' => uniqid('recette-'), 'market_key' => 'FR', 'address_country_code' => 'FR', 'jobdomain_key' => 'logistique']);
        $this->wipe($c);

        $sub = Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'stripe', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth(),
        ]);
        $inv = $this->invoice($c, 'INV-RECETTE-0002', null, null, 2900, 580, now()->subDays(2), 'paid', now()->subDays(2), $sub->id);
        $this->line($inv, 'plan', null, 'Abonnement Pro', 2900);
        Payment::create(['subscription_id' => $sub->id, 'invoice_id' => $inv->id, 'company_id' => $c->id, 'amount' => 3480, 'currency' => 'EUR', 'status' => 'succeeded', 'provider' => 'stripe', 'provider_payment_id' => 'pi_recette_ok']);
    }

    /** Company C — genuine payment problem: an OVERDUE invoice + a FAILED payment. */
    private function seedOverdueFailedPaymentCase(): void
    {
        $c = Company::firstOrCreate(['name' => '[RECETTE] Overdue Failed'], ['slug' => uniqid('recette-'), 'market_key' => 'FR', 'address_country_code' => 'FR', 'jobdomain_key' => 'logistique']);
        $this->wipe($c);

        $sub = Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'past_due',
            'provider' => 'stripe', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->subMonth()->startOfMonth(), 'current_period_end' => now()->subMonth()->endOfMonth(),
        ]);
        $inv = $this->invoice($c, 'INV-RECETTE-0003', null, null, 2900, 580, now()->subDays(10), 'overdue', null, $sub->id);
        $this->line($inv, 'plan', null, 'Abonnement Pro', 2900);
        Payment::create(['subscription_id' => $sub->id, 'invoice_id' => $inv->id, 'company_id' => $c->id, 'amount' => 3480, 'currency' => 'EUR', 'status' => 'failed', 'provider' => 'stripe', 'provider_payment_id' => 'pi_recette_fail']);
    }

    private function wipe(Company $c): void
    {
        $ids = Invoice::where('company_id', $c->id)->pluck('id');
        InvoiceLine::whereIn('invoice_id', $ids)->delete();
        Payment::where('company_id', $c->id)->delete();
        Invoice::where('company_id', $c->id)->delete();
        Subscription::where('company_id', $c->id)->delete();
        CompanyAddonSubscription::where('company_id', $c->id)->delete();
    }

    private function invoice(Company $c, ?string $number, ?int $parent, ?string $annexe, int $subtotal, int $tax, $dueAt, string $status = 'open', $paidAt = null, ?int $subId = null): Invoice
    {
        return Invoice::create([
            'company_id' => $c->id, 'subscription_id' => $subId, 'parent_invoice_id' => $parent, 'annexe_suffix' => $annexe,
            'number' => $number, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'tax_rate_bps' => 2000,
            'amount' => $subtotal + $tax, 'amount_due' => $status === 'paid' ? 0 : $subtotal + $tax, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => $status, 'issued_at' => now()->subDays(3), 'finalized_at' => now()->subDays(3),
            'due_at' => $dueAt, 'paid_at' => $paidAt,
        ]);
    }

    private function line(Invoice $inv, string $type, ?string $moduleKey, string $desc, int $amount): void
    {
        InvoiceLine::create([
            'invoice_id' => $inv->id, 'type' => $type, 'module_key' => $moduleKey, 'description' => $desc,
            'quantity' => 1, 'unit_amount' => $amount, 'amount' => $amount, 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]);
    }
}
