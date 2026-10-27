<?php

namespace Database\Seeders;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillingInvoiceLineAnalytic;
use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
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

        $this->seedOverdueProvisionalCase();      // reproduces Leezr Logistics — blocked renewal (provisional)
        $this->seedHealthyPaidCase();             // confirmed price, paid, card on file — cancellable subscription
        $this->seedOverdueFailedPaymentCase();    // genuine payment problem — failed payment + overdue invoice
        $this->seedOwnerCompanyInvoice();         // a finalized invoice on the owner persona's company (browser recette)
        $this->seedOperationalArtifactsCase();    // dead-letter + void + not-due invoices + wallet movements
        $this->seedConsolidatedV2Case();          // ADR-632 (M9): V2 consolidated invoices for the UI recette
    }

    /**
     * ADR-632 (M9) — V2 consolidated-invoice fixtures for the Company/Platform UI recette.
     *
     * These are READ-MODEL fixtures: the consolidated invoice + its analytic ventilation are built
     * directly (no emission pipeline, no feature flag toggled — billing.consolidated.emit stays OFF),
     * so a browser recette has real V2 data WITHOUT activating emission on any company. Analytics are
     * derived from the invoice lines by LedgerService, exactly like a real V2 emission would produce,
     * so the sections reconcile to the cent. Attached to the owner persona's company so the owner can
     * browse list → V2 detail → PDF in the same account that already carries a V1 invoice.
     *
     * Two fixtures: a readable mixed invoice (plan + addons + a mid-cycle prorata + discount +
     * adjustment) and a large 135-line invoice to exercise multipage PDF + responsive rendering.
     */
    private function seedConsolidatedV2Case(): void
    {
        $user = \App\Core\Models\User::where('email', 'owner@leezr.test')->first();
        $company = $user?->memberships()->where('role', 'owner')->first()?->company
            ?? Company::where('name', 'Leezr Logistics')->first();
        if (! $company) {
            return;
        }

        // Idempotent wipe of prior V2 recette invoices (+ lines + analytics + events).
        $prior = Invoice::where('company_id', $company->id)
            ->whereIn('number', ['INV-RECETTE-V2', 'INV-RECETTE-V2-LARGE'])->pluck('id');
        if ($prior->isNotEmpty()) {
            BillingInvoiceLineAnalytic::whereIn('invoice_id', $prior)->delete();
            BillableEvent::whereIn('invoice_line_id', InvoiceLine::whereIn('invoice_id', $prior)->pluck('id'))->delete();
            InvoiceLine::whereIn('invoice_id', $prior)->delete();
            Invoice::whereIn('id', $prior)->delete();
        }

        $sub = Subscription::where('company_id', $company->id)->where('is_current', true)->first()?->id;

        // ── Fixture 1 : readable mixed consolidated invoice ──
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->startOfMonth()->addMonth(); // semi-open [start, end)
        $lines = [
            ['plan', null, 'Abonnement Pro', 2900, $periodStart, $periodEnd, false],
            ['addon', 'workforce_leave', 'Module Congés & Absences', 1900, $periodStart, $periodEnd, false],
            ['addon', 'workforce_planning', 'Module Planning & Pointage', 2900, $periodStart, $periodEnd, false],
            // Mid-cycle activation → prorata (backed by a BillableEvent → "Prorata" badge in the UI).
            ['addon', 'workforce_documents', 'Module Documents Employés (prorata)', 465, now()->startOfMonth()->addDays(16), $periodEnd, true],
            ['discount', null, 'Remise fidélité', -500, $periodStart, $periodEnd, false],
            ['charge', null, 'Ajustement commercial', 300, $periodStart, $periodEnd, false],
        ];
        $subtotal = array_sum(array_map(fn ($l) => $l[3], $lines));
        $tax = (int) round($subtotal * 0.20);
        $inv = $this->v2Invoice($company, 'INV-RECETTE-V2', $subtotal, $tax, $sub);
        foreach ($lines as [$type, $mod, $desc, $amount, $ps, $pe, $isProrata]) {
            $line = $this->v2Line($inv, $type, $mod, $desc, $amount, $ps, $pe);
            if ($isProrata) {
                BillableEvent::create([
                    'company_id' => $company->id, 'subscription_id' => $sub, 'currency' => 'EUR',
                    'type' => 'module_activation', 'module_key' => $mod,
                    'service_period_start' => $ps, 'service_period_end' => $pe,
                    'amount_ht_cents' => $amount, 'status' => 'invoiced', 'invoice_line_id' => $line->id,
                    'idempotency_key' => 'recette-v2-prorata-'.$line->id,
                ]);
            }
        }
        LedgerService::recordInvoiceAnalytics($inv->fresh());

        // ── Fixture 2 : large 135-line invoice (multipage PDF + responsive) ──
        $big = $this->v2Invoice($company, 'INV-RECETTE-V2-LARGE', 0, 0, $sub);
        $this->v2Line($big, 'plan', null, 'Abonnement Pro', 2900, $periodStart, $periodEnd);
        for ($i = 1; $i <= 134; $i++) {
            $this->v2Line($big, 'addon', "mod_{$i}", "Module #{$i}", 100, $periodStart, $periodEnd);
        }
        $bigSubtotal = 2900 + 134 * 100;
        $bigTax = (int) round($bigSubtotal * 0.20);
        $big->update(['subtotal' => $bigSubtotal, 'tax_amount' => $bigTax, 'amount' => $bigSubtotal + $bigTax, 'amount_due' => $bigSubtotal + $bigTax]);
        LedgerService::recordInvoiceAnalytics($big->fresh());
    }

    private function v2Invoice(Company $c, string $number, int $subtotal, int $tax, ?int $subId): Invoice
    {
        return Invoice::create([
            'company_id' => $c->id, 'subscription_id' => $subId, 'billing_model_version' => 2,
            'number' => $number, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'tax_rate_bps' => 2000,
            'amount' => $subtotal + $tax, 'amount_due' => $subtotal + $tax, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now()->subDay(), 'finalized_at' => now()->subDay(),
            'due_at' => now()->addMonth(), 'period_start' => now()->startOfMonth(), 'period_end' => now()->startOfMonth()->addMonth(),
        ]);
    }

    private function v2Line(Invoice $inv, string $type, ?string $moduleKey, string $desc, int $amount, $periodStart, $periodEnd): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $inv->id, 'type' => $type, 'module_key' => $moduleKey, 'description' => $desc,
            'quantity' => 1, 'unit_amount' => $amount, 'amount' => $amount,
            'period_start' => $periodStart, 'period_end' => $periodEnd,
        ]);
    }

    /**
     * Operational artifacts for the recette matrix (LOT 8 / recette finale) — the remaining scenarios
     * that let the real UI interactions be exercised (not frontend-faked):
     *   - a financial dead-letter (inspectable + replayable, ADR-631g);
     *   - a VOID invoice (status truth + forbidden credit-note guard, ADR-631c/d);
     *   - a NOT-DUE invoice (open, due in the future);
     *   - a wallet with real credit/debit movements (activity/history).
     */
    private function seedOperationalArtifactsCase(): void
    {
        // 1) A pending financial dead-letter (idempotent on event_id).
        BillingWebhookDeadLetter::updateOrCreate(
            ['event_id' => 'evt_recette_failed_1'],
            [
                'provider_key' => 'stripe',
                'event_type' => 'invoice.payment_failed',
                'payload' => ['id' => 'evt_recette_failed_1', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['amount_due' => 3480, 'currency' => 'eur', 'attempt_count' => 2]]],
                'error_message' => 'Signature verification failed (recette fixture)',
                'failed_at' => now()->subHours(2),
                'status' => 'pending',
                'replay_attempts' => 1,
            ],
        );

        // 2) A company carrying a void invoice, a not-due invoice, and wallet movements.
        $c = Company::firstOrCreate(['name' => '[RECETTE] Financial Artifacts'], ['slug' => uniqid('recette-'), 'market_key' => 'FR', 'address_country_code' => 'FR', 'jobdomain_key' => 'logistique']);
        $this->wipe($c);
        Invoice::where('company_id', $c->id)->delete();

        $sub = Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20),
        ]);

        // Not-due invoice (open, future due date).
        $notDue = $this->invoice($c, 'INV-RECETTE-NOTDUE', null, null, 2900, 580, now()->addDays(20), 'open', null, $sub->id);
        $this->line($notDue, 'plan', null, '[RECETTE] Plan Pro (not due)', 2900);

        // Void invoice (finalized then voided) — exercises void status + forbidden credit-note guard.
        $void = $this->invoice($c, 'INV-RECETTE-VOID', null, null, 2900, 580, now()->subDays(5), 'void', null, $sub->id);
        $void->update(['voided_at' => now()->subDays(1), 'amount_due' => 0]);
        $this->line($void, 'plan', null, '[RECETTE] Plan Pro (voided)', 2900);

        // Wallet movements (credit then partial debit) — activity/history recette.
        WalletLedger::ensureWallet($c);
        WalletLedger::credit($c, 5000, 'recette_fixture', null, '[RECETTE] Crédit initial', 'system', null, 'recette-wallet-credit');
        WalletLedger::debit($c, 1500, 'recette_fixture', null, '[RECETTE] Débit partiel', 'system', null, 'recette-wallet-debit');
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

        // Wipe the parent AND its annexes (children) to stay idempotent.
        $parents = Invoice::where('company_id', $company->id)->where('number', 'INV-RECETTE-OWNER')->pluck('id');
        Invoice::whereIn('parent_invoice_id', $parents)->get()->each(function ($a) {
            InvoiceLine::where('invoice_id', $a->id)->delete();
            Payment::where('invoice_id', $a->id)->delete();
            $a->delete();
        });
        Invoice::whereIn('id', $parents)->get()->each(function ($i) {
            InvoiceLine::where('invoice_id', $i->id)->delete();
            Payment::where('invoice_id', $i->id)->delete();
            $i->delete();
        });

        // Parent invoice (plan) — carries the real number.
        $inv = $this->invoice($company, 'INV-RECETTE-OWNER', null, null, 4800, 960, now()->addMonth(), 'open');
        $this->line($inv, 'plan', null, 'Abonnement Pro', 2900);
        $this->line($inv, 'addon', 'workforce_leave', 'Addon Congés & Absences', 1900);

        // ADR-328 annexes (number=NULL, own balance) — reproduce the "module invoice without a name"
        // scenario so /company/billing/pay can be validated: each annexe carries an addon module line.
        $annexeA = $this->invoice($company, null, $inv->id, 'A', 2900, 580, now()->addMonth(), 'open');
        $this->line($annexeA, 'addon', 'workforce_planning', 'Addon Planning & Pointage', 2900);
        $annexeB = $this->invoice($company, null, $inv->id, 'B', 900, 180, now()->addMonth(), 'open');
        $this->line($annexeB, 'addon', 'workforce_documents', 'Addon Documents Employés', 900);
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
