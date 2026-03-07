<!DOCTYPE html>
<html lang="{{ $locale ?? 'fr-FR' }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $invoice->number }}</title>
    <style>
        @page { margin: 30px 40px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }

        .logo { font-size: 24px; font-weight: 700; color: #4b4b4b; }
        .logo .dot { color: #7367f0; }

        /* Header table (replaces flexbox) */
        .header-table { width: 100%; margin-bottom: 20px; border-bottom: 1px solid #e0e0e0; padding-bottom: 15px; }
        .header-table td { vertical-align: top; }
        .header-left { width: 50%; }
        .header-right { width: 50%; text-align: right; }
        .invoice-number { font-size: 16px; font-weight: 600; margin-bottom: 8px; }

        .status { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .status-open, .status-overdue { background: #fff3e0; color: #e65100; }
        .status-paid { background: #e8f5e9; color: #2e7d32; }
        .status-voided { background: #f5f5f5; color: #666; }
        .status-draft { background: #f5f5f5; color: #999; }

        .meta { font-size: 11px; color: #666; }
        .meta p { margin: 2px 0; }

        /* Billing row */
        .billing-table { width: 100%; margin-bottom: 20px; }
        .billing-table td { vertical-align: top; width: 50%; }
        .billing-table h3 { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .billing-table .legal-name { font-weight: 600; font-size: 12px; }
        .billing-table .detail { font-size: 11px; color: #555; margin: 1px 0; }

        .details-inner td { font-size: 11px; padding: 1px 0; }
        .details-inner .label { color: #888; padding-right: 8px; }

        /* Line items */
        .items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items th { background: #f5f5f9; text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #666; border-bottom: 1px solid #e0e0e0; }
        .items td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; font-size: 11px; }
        .items .text-end { text-align: right; }
        .items .text-center { text-align: center; }
        .items .type-chip { background: #f5f5f9; color: #666; padding: 1px 6px; border-radius: 3px; font-size: 9px; text-transform: uppercase; }

        /* Totals */
        .totals-table { width: 100%; margin-bottom: 15px; }
        .totals-table .note-col { width: 55%; vertical-align: top; font-size: 11px; color: #666; }
        .totals-table .note-col strong { color: #333; }
        .totals-table .amounts-col { width: 45%; vertical-align: top; }
        .amounts-inner { width: 100%; }
        .amounts-inner td { padding: 3px 0; font-size: 11px; }
        .amounts-inner .label { color: #666; }
        .amounts-inner .value { text-align: right; font-weight: 500; }
        .amounts-inner .value.credit { color: #2e7d32; }
        .amounts-inner .divider { border-top: 1px solid #e0e0e0; height: 1px; }
        .amounts-inner .total-label { font-size: 13px; font-weight: 600; color: #333; }
        .amounts-inner .total-value { text-align: right; font-size: 13px; font-weight: 700; color: #333; }
        .amounts-inner .due-value { text-align: right; font-weight: 600; color: #c62828; }

        /* Due notice */
        .due-notice { background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px; padding: 8px 12px; margin-bottom: 15px; font-size: 11px; color: #e65100; font-weight: 600; }

        .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #eee; font-size: 9px; color: #aaa; text-align: center; }
    </style>
</head>
<body>
    @php
        $snap = $snap ?? ($invoice->billing_snapshot ?? []);
        $locale = $locale ?? ($snap['market_locale'] ?? 'fr-FR');
        $isFr = str_starts_with($locale, 'fr');

        // Status labels
        $statusLabels = $isFr
            ? ['draft' => 'Brouillon', 'open' => 'À régler', 'overdue' => 'En retard de paiement', 'paid' => 'Payée', 'voided' => 'Annulée', 'uncollectible' => 'Irrécouvrable']
            : ['draft' => 'Draft', 'open' => 'Due', 'overdue' => 'Past Due', 'paid' => 'Paid', 'voided' => 'Voided', 'uncollectible' => 'Uncollectible'];

        $typeLabels = $isFr
            ? ['plan_change' => 'Changement de plan', 'proration' => 'Prorata', 'credit' => 'Crédit', 'charge' => 'Facturation', 'addon' => 'Module', 'renewal' => 'Renouvellement']
            : ['plan_change' => 'Plan change', 'proration' => 'Proration', 'credit' => 'Credit', 'charge' => 'Charge', 'addon' => 'Addon', 'renewal' => 'Renewal'];

        // Labels
        $l = $isFr ? [
            'invoice' => 'Facture',
            'issuedAt' => 'Émise le',
            'dueAt' => 'Échéance',
            'paidAt' => 'Payée le',
            'invoiceTo' => 'Facturé à',
            'billingDetails' => 'Détails de facturation',
            'period' => 'Période',
            'market' => 'Marché',
            'legalStatus' => 'Statut juridique',
            'currency' => 'Devise',
            'vat' => 'N° TVA',
            'siret' => 'SIRET',
            'description' => 'Description',
            'type' => 'Type',
            'qty' => 'Qté',
            'unitPrice' => 'Prix unitaire',
            'total' => 'Total',
            'subtotal' => 'Sous-total',
            'tax' => 'Taxes',
            'walletCredit' => 'Crédit portefeuille',
            'amountDue' => 'Montant dû',
            'notes' => 'Notes',
            'dueNotice' => 'En attente de paiement — échéance dépassée',
            'generated' => 'Généré le',
        ] : [
            'invoice' => 'Invoice',
            'issuedAt' => 'Issued',
            'dueAt' => 'Due',
            'paidAt' => 'Paid',
            'invoiceTo' => 'Invoice To',
            'billingDetails' => 'Billing Details',
            'period' => 'Period',
            'market' => 'Market',
            'legalStatus' => 'Legal Status',
            'currency' => 'Currency',
            'vat' => 'VAT',
            'siret' => 'SIRET',
            'description' => 'Description',
            'type' => 'Type',
            'qty' => 'Qty',
            'unitPrice' => 'Unit Price',
            'total' => 'Total',
            'subtotal' => 'Subtotal',
            'tax' => 'Tax',
            'walletCredit' => 'Wallet Credit',
            'amountDue' => 'Amount Due',
            'notes' => 'Notes',
            'dueNotice' => 'Payment pending — past due',
            'generated' => 'Generated on',
        ];

        // Date formatter
        $fmtDate = fn($d) => $d ? \Carbon\Carbon::parse($d)->locale($locale)->isoFormat('D MMMM YYYY') : '—';

        // Money formatter
        $cur = $snap['currency'] ?? $invoice->currency ?? 'EUR';
        $fmtMoney = fn($cents) => number_format($cents / 100, 2, ',', ' ') . ' ' . $cur;

        $isOverdue = $invoice->status === 'overdue' || ($invoice->status === 'open' && $invoice->due_at && $invoice->due_at->isPast());

        // Commercial display status: open+past-due → show as overdue
        $displayStatus = $isOverdue && $invoice->status === 'open' ? 'overdue' : $invoice->status;
    @endphp

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td class="header-left">
                <div class="logo">leezr<span class="dot">.</span></div>
            </td>
            <td class="header-right">
                <div class="invoice-number">{{ $invoice->number }}</div>
                <div>
                    <span class="status status-{{ $displayStatus }}">{{ $statusLabels[$displayStatus] ?? ucfirst($displayStatus) }}</span>
                </div>
                <div class="meta" style="margin-top: 6px;">
                    <p>{{ $l['issuedAt'] }} : {{ $fmtDate($invoice->issued_at) }}</p>
                    <p>{{ $l['dueAt'] }} : {{ $fmtDate($invoice->due_at) }}</p>
                    @if($invoice->paid_at)
                        <p>{{ $l['paidAt'] }} : {{ $fmtDate($invoice->paid_at) }}</p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Due notice for unpaid past-due invoices -->
    @if($isOverdue && $invoice->status !== 'paid')
        <div class="due-notice">
            ⚠ {{ $l['dueNotice'] }}
        </div>
    @endif

    <!-- Invoice To + Billing Details -->
    <table class="billing-table">
        <tr>
            <td>
                <h3>{{ $l['invoiceTo'] }}</h3>
                <p class="legal-name">{{ $snap['company_legal_name'] ?? $snap['company_name'] ?? $company->name }}</p>
                @if(!empty($snap['billing_address']))
                    <p class="detail">{{ $snap['billing_address'] }}</p>
                @endif
                @if(!empty($snap['billing_email']))
                    <p class="detail">{{ $snap['billing_email'] }}</p>
                @endif
                @if(!empty($snap['vat_number']))
                    <p class="detail">{{ $l['vat'] }} : {{ $snap['vat_number'] }}</p>
                @endif
                @if(!empty($snap['siret']))
                    <p class="detail">{{ $l['siret'] }} : {{ $snap['siret'] }}</p>
                @endif
            </td>
            <td>
                <h3>{{ $l['billingDetails'] }}</h3>
                <table class="details-inner">
                    @if($invoice->period_start && $invoice->period_end)
                        <tr>
                            <td class="label">{{ $l['period'] }}</td>
                            <td>{{ $fmtDate($invoice->period_start) }} – {{ $fmtDate($invoice->period_end) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="label">{{ $l['dueAt'] }}</td>
                        <td>{{ $fmtDate($invoice->due_at) }}</td>
                    </tr>
                    @if(!empty($snap['market_name']))
                        <tr>
                            <td class="label">{{ $l['market'] }}</td>
                            <td>{{ $snap['market_name'] }}</td>
                        </tr>
                    @endif
                    @if(!empty($snap['legal_status_name']))
                        <tr>
                            <td class="label">{{ $l['legalStatus'] }}</td>
                            <td>{{ $snap['legal_status_name'] }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <!-- Line Items -->
    <table class="items">
        <thead>
            <tr>
                <th>{{ $l['description'] }}</th>
                <th>{{ $l['type'] }}</th>
                <th class="text-center">{{ $l['qty'] }}</th>
                <th class="text-end">{{ $l['unitPrice'] }}</th>
                <th class="text-end">{{ $l['total'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td><span class="type-chip">{{ $typeLabels[$line->type] ?? $line->type }}</span></td>
                <td class="text-center">{{ $line->quantity }}</td>
                <td class="text-end">{{ $fmtMoney($line->unit_amount) }}</td>
                <td class="text-end">{{ $fmtMoney($line->amount) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals + Note -->
    <table class="totals-table">
        <tr>
            <td class="note-col">
                @if($invoice->notes)
                    <p><strong>{{ $l['notes'] }} :</strong> {{ $invoice->notes }}</p>
                @endif
            </td>
            <td class="amounts-col">
                <table class="amounts-inner">
                    <tr>
                        <td class="label">{{ $l['subtotal'] }}</td>
                        <td class="value">{{ $fmtMoney($invoice->subtotal) }}</td>
                    </tr>
                    @if($invoice->tax_amount > 0)
                    <tr>
                        <td class="label">{{ $l['tax'] }} ({{ number_format($invoice->tax_rate_bps / 100, 2) }}%)</td>
                        <td class="value">{{ $fmtMoney($invoice->tax_amount) }}</td>
                    </tr>
                    @endif
                    @if($invoice->wallet_credit_applied > 0)
                    <tr>
                        <td class="label">{{ $l['walletCredit'] }}</td>
                        <td class="value credit">-{{ $fmtMoney($invoice->wallet_credit_applied) }}</td>
                    </tr>
                    @endif
                    <tr><td colspan="2"><div class="divider"></div></td></tr>
                    <tr>
                        <td class="total-label">{{ $l['total'] }}</td>
                        <td class="total-value">{{ $fmtMoney($invoice->amount) }}</td>
                    </tr>
                    @if($invoice->amount_due !== $invoice->amount)
                    <tr>
                        <td class="label">{{ $l['amountDue'] }}</td>
                        <td class="due-value">{{ $fmtMoney($invoice->amount_due) }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="footer">
        <p>{{ $l['generated'] }} {{ now()->locale($locale)->isoFormat('D MMMM YYYY [à] HH:mm') }}</p>
    </div>
</body>
</html>
