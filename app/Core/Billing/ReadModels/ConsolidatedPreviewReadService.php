<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;

/**
 * ADR-632 — « Aperçu consolidé V2 » : projection VISUELLE, STRICTEMENT EN LECTURE SEULE, des factures
 * V1 existantes d'une Company, regroupées par famille de prestation. Ce n'est PAS un document
 * comptable : aucune facture n'est fusionnée, créée ni modifiée ; aucun montant n'est recalculé
 * (HT/TVA/TTC proviennent des factures persistées) ; aucun prix provisional n'est ajouté aux créances.
 *
 * Règles :
 *  - une facture existante = une ligne d'aperçu (parent OU annexe), jamais dédoublée ;
 *  - total « factures existantes » = somme des factures réelles finalisées non annulées ;
 *  - prix contractuel non fiable (provisional) → section « Simulation non facturée », JAMAIS dans le total ;
 *  - période de prestation absente → « à confirmer » (aucune période fabriquée) ;
 *  - une seule devise ; multi-devises → signalé, pas mélangé.
 */
class ConsolidatedPreviewReadService
{
    public static function forCompany(Company $company): array
    {
        // Factures V1 réelles : finalisées, non annulées, de cette Company (parents + annexes).
        // Les factures V2 (déjà consolidées) sont exclues — elles disposent de leur propre détail.
        $invoices = Invoice::where('company_id', $company->id)
            ->whereNotNull('finalized_at')
            ->where('status', '!=', 'void')
            ->where('billing_model_version', '!=', 2)
            ->with('lines')
            ->orderBy('id')
            ->get();

        $currencies = $invoices->pluck('currency')->filter()->unique()->values();
        $currency = $currencies->first() ?? 'EUR';
        $multiCurrency = $currencies->count() > 1;
        // Ne jamais mélanger : on ne projette que la devise dominante.
        $scoped = $invoices->filter(fn ($i) => ($i->currency ?? $currency) === $currency);

        $families = ['subscription' => [], 'modules' => [], 'other' => []];
        foreach ($scoped as $inv) {
            $primary = $inv->lines->first(fn ($l) => in_array($l->type, ['plan', 'addon'], true)) ?? $inv->lines->first();
            $familyKey = match ($primary?->type) {
                'plan' => 'subscription',
                'addon' => 'modules',
                default => 'other',
            };
            $hasPeriod = $primary && $primary->period_start && $primary->period_end;

            $families[$familyKey][] = [
                'prestation_label' => $primary?->description ?: ($primary?->module_key ?: $inv->displayNumber()),
                'module_key' => $primary?->module_key,
                'invoice_id' => $inv->id,
                'invoice_ref' => $inv->displayNumber(),
                'is_annexe' => $inv->parent_invoice_id !== null,
                'period_known' => (bool) $hasPeriod,
                'period_start' => $hasPeriod ? $primary->period_start->toDateString() : null,
                'period_end' => $hasPeriod ? $primary->period_end->toDateString() : null,
                // Montants = valeurs PERSISTÉES de la facture (aucun recalcul de taxe).
                'ht_cents' => (int) $inv->subtotal,
                'tax_cents' => (int) $inv->tax_amount,
                'ttc_cents' => (int) $inv->amount,
                'paid_cents' => (int) $inv->amount - (int) $inv->amount_due,
                'due_cents' => (int) $inv->amount_due,
                'status' => $inv->status,
            ];
        }

        $sections = [];
        foreach ($families as $key => $rows) {
            if (empty($rows)) {
                continue;
            }
            $sections[] = [
                'key' => $key,
                'rows' => $rows,
                'subtotal_ht_cents' => array_sum(array_column($rows, 'ht_cents')),
                'subtotal_tax_cents' => array_sum(array_column($rows, 'tax_cents')),
                'subtotal_ttc_cents' => array_sum(array_column($rows, 'ttc_cents')),
            ];
        }

        // Total des factures EXISTANTES (réelles) — exclut toute simulation provisional.
        $existingTotal = [
            'ht_cents' => (int) $scoped->sum('subtotal'),
            'tax_cents' => (int) $scoped->sum('tax_amount'),
            'ttc_cents' => (int) $scoped->sum('amount'),
            'paid_cents' => (int) $scoped->sum('amount') - (int) $scoped->sum('amount_due'),
            'due_cents' => (int) $scoped->sum('amount_due'),
        ];

        // Simulation non facturée : plan contractuel non fiable (provisional) jamais facturé.
        $provisional = static::provisionalSimulation($company, $scoped, $currency);

        return [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'currency' => $currency,
            'multi_currency' => $multiCurrency,
            'is_simulation' => true,
            'sections' => $sections,
            'existing_total' => $existingTotal,
            'provisional_simulation' => $provisional,
            'invoice_count' => $scoped->count(),
        ];
    }

    /**
     * Plan contractuel non fiable (provisional) et NON facturé → affiché à titre indicatif seulement,
     * HT uniquement, jamais ajouté au total des créances. Retourne [] si aucun.
     */
    private static function provisionalSimulation(Company $company, $scopedInvoices, string $currency): array
    {
        $sub = Subscription::where('company_id', $company->id)
            ->where(fn ($q) => $q->where('is_current', true)->orWhere('status', 'active'))
            ->orderByDesc('is_current')
            ->first();

        if (! $sub || ! $sub->plan_key || ! $sub->plan_price_cents) {
            return [];
        }
        // Fiable → déjà couvert par le flux normal, pas de simulation.
        if ($sub->hasReliableContractedPrice()) {
            return [];
        }
        // Le plan est-il déjà facturé (ligne 'plan' sur une facture existante) ? Si oui, pas de simulation.
        $planAlreadyBilled = $scopedInvoices->contains(fn ($i) => $i->lines->contains('type', 'plan'));
        if ($planAlreadyBilled) {
            return [];
        }

        $planName = Plan::where('key', $sub->plan_key)->value('name') ?? ucfirst($sub->plan_key);

        return [[
            'label' => $planName,
            'plan_key' => $sub->plan_key,
            'ht_cents_indicative' => (int) $sub->plan_price_cents,
            'currency' => $currency,
            'price_source' => $sub->plan_price_source,
            'note' => 'provisional_not_billed', // i18n côté front
        ]];
    }
}
