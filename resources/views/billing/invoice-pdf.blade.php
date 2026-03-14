<!DOCTYPE html>
<html lang="{{ $locale ?? 'fr-FR' }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $invoice->number }}</title>
    @php
        $fontFamily = $typography['active_family_name'] ?? null;
        $fontSource = $typography['active_source'] ?? null;
        $fontCss = 'DejaVu Sans, sans-serif';
        if ($fontFamily) {
            $fontCss = "'{$fontFamily}', DejaVu Sans, sans-serif";
        }
    @endphp
    @if(!empty($fontFaceCss))
        <style>{!! $fontFaceCss !!}</style>
    @elseif($fontFamily && $fontSource === 'local' && !empty($typography['font_faces']))
        <style>
            @foreach($typography['font_faces'] as $face)
            @font-face {
                font-family: '{{ $fontFamily }}';
                font-weight: {{ $face['weight'] }};
                font-style: {{ $face['style'] }};
                src: url('{{ $face['url'] }}') format('{{ $face['format'] }}');
            }
            @endforeach
        </style>
    @endif
    @php $brandColor = $primaryColor ?? '#7367F0'; @endphp
    <style>
        @page { margin: 40px 50px 70px 50px; }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body, table, th, td, div, span, p, strong, b, tr {
            font-family: {!! $fontCss !!};
        }

        body { font-size: 10px; color: #2F2B3D; line-height: 1.3; }

        .card { border: 1px solid #e8e5ef; border-radius: 6px; padding: 24px 28px; margin-bottom: 0; }

        /* ─── Header ─── */
        .header-table { width: 100%; margin-bottom: 20px; }
        .header-table td { vertical-align: top; }
        .header-left { width: 50%; }
        .header-right { width: 50%; text-align: right; }

        .logo { font-size: 38px; font-weight: 700; color: #2F2B3D; letter-spacing: -0.5px; line-height: 1; }
        .logo .dot { color: {{ $brandColor }}; }

        .invoice-number { font-size: 14px; font-weight: 600; color: #2F2B3D; margin-bottom: 4px; }

        .status {
            display: inline-block; padding: 4px 10px; border-radius: 4px;
            font-size: 9px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.3px; line-height: 1;
        }
        .status-draft { background: #F5F5F9; color: #A5A2AD; }
        .status-open { background: #FFF3E0; color: #E65100; }
        .status-overdue { background: #FFEBEE; color: #C62828; }
        .status-paid { background: #E8F5E9; color: #2E7D32; }
        .status-voided { background: #F5F5F9; color: #8C8798; }
        .status-uncollectible { background: #FFEBEE; color: #C62828; }

        .meta { font-size: 9.5px; color: #6D6B77; margin-top: 6px; }
        .meta p { margin: 1px 0; }

        /* ─── Billing info ─── */
        .billing-table { width: 100%; margin-bottom: 20px; }
        .billing-table td { vertical-align: top; width: 50%; }

        .section-title {
            font-size: 9px; font-weight: 600; color: {{ $brandColor }};
            text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 5px;
        }
        .legal-name { font-weight: 600; font-size: 11px; color: #2F2B3D; margin-bottom: 1px; }
        .detail { font-size: 9.5px; color: #6D6B77; margin: 1px 0; }

        .details-inner td { font-size: 9.5px; padding: 1px 0; }
        .details-inner .lbl { color: #A5A2AD; padding-right: 8px; white-space: nowrap; }
        .details-inner .val { color: #2F2B3D; }

        /* ─── Due notice ─── */
        .due-notice {
            background: #FFF8E1; border: 1px solid #FFE082; border-radius: 4px;
            padding: 7px 12px; margin-bottom: 12px;
            font-size: 10px; color: #E65100; font-weight: 600;
        }

        /* ─── Line items ─── */
        .items {
            width: 100%; border-collapse: collapse; margin-bottom: 14px;
            border: 1px solid #e8e5ef; border-radius: 4px;
        }
        .items th {
            background: #F5F5F9; text-align: left;
            padding: 6px 10px; font-size: 9px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            color: #6D6B77; border-bottom: 1px solid #e8e5ef;
        }
        .items td {
            padding: 5px 10px; border-bottom: 1px solid #f2f0f6;
            font-size: 10px; color: #2F2B3D;
        }
        .items tr:last-child td { border-bottom: none; }

        .text-end { text-align: right; }
        .text-center { text-align: center; }

        .type-chip {
            background: #F5F5F9; color: #6D6B77;
            padding: 1px 6px 2px 6px; border-radius: 3px;
            font-size: 8px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.2px;
        }

        /* ─── Totals ─── */
        .totals-table { width: 100%; }
        .totals-table .note-col { width: 55%; vertical-align: top; font-size: 9.5px; color: #6D6B77; }
        .totals-table .note-col strong { color: #2F2B3D; }
        .totals-table .amounts-col { width: 45%; vertical-align: top; }

        .amounts-inner { width: 100%; }
        .amounts-inner td { padding: 2px 0; font-size: 10px; font-family: {!! $fontCss !!}; }
        .amounts-inner .lbl { color: #6D6B77; font-family: {!! $fontCss !!}; }
        .amounts-inner .val { text-align: right; font-weight: 400; color: #2F2B3D; font-family: {!! $fontCss !!}; }
        .amounts-inner .val.credit { color: #2E7D32; }
        .amounts-inner .sep td { padding: 4px 0; }
        .amounts-inner .total-lbl { font-size: 11px; font-weight: 700; color: #2F2B3D; font-family: {!! $fontCss !!}; }
        .amounts-inner .total-val { text-align: right; font-size: 11px; font-weight: 700; color: #2F2B3D; font-family: {!! $fontCss !!}; }
        .amounts-inner .due-lbl { font-size: 10px; color: #C62828; font-weight: 600; font-family: {!! $fontCss !!}; }
        .amounts-inner .due-val { text-align: right; font-size: 10px; font-weight: 700; color: #C62828; font-family: {!! $fontCss !!}; }

        /* ─── Info boxes ─── */
        .info-box {
            background: #F5F5F9; border-radius: 4px;
            padding: 6px 12px; font-size: 9px; color: #6D6B77; margin-top: 8px;
        }
        .info-box strong { color: #2F2B3D; }
        .info-box.info-blue { background: #E3F2FD; color: #1565C0; }

        /* ─── Section titles (payments, credit notes) ─── */
        .section-title {
            font-size: 11px; font-weight: 600; color: #2F2B3D;
            margin-top: 16px; margin-bottom: 6px;
        }

        /* ─── Footer ─── */
        .footer {
            margin-top: 30px;
            padding: 0 28px;
            font-size: 7.5px;
            color: #A5A2AD;
            line-height: 1.5;
            page-break-inside: avoid;
            text-align: center;
        }
        .footer-main { margin-bottom: 4px; }
        .footer-main strong { font-weight: 600; color: #6D6B77; font-size: 8px; }
        .footer-generated { margin-top: 6px; font-size: 7.5px; color: #b5b2bd; text-align: center; }
    </style>
</head>
<body>
    @php
        $snap = $snap ?? ($invoice->billing_snapshot ?? []);
        $locale = $locale ?? ($snap['market_locale'] ?? 'fr-FR');
        $isFr = str_starts_with($locale, 'fr');

        $statusLabels = $isFr
            ? ['draft' => 'Brouillon', 'open' => 'À régler', 'overdue' => 'En retard', 'paid' => 'Payée', 'voided' => 'Annulée', 'uncollectible' => 'Irrécouvrable']
            : ['draft' => 'Draft', 'open' => 'Due', 'overdue' => 'Past Due', 'paid' => 'Paid', 'voided' => 'Voided', 'uncollectible' => 'Uncollectible'];

        $typeLabels = $isFr
            ? ['plan_change' => 'Changement', 'proration' => 'Prorata', 'credit' => 'Crédit', 'charge' => 'Facturation', 'addon' => 'Module', 'renewal' => 'Renouvellement']
            : ['plan_change' => 'Plan change', 'proration' => 'Proration', 'credit' => 'Credit', 'charge' => 'Charge', 'addon' => 'Addon', 'renewal' => 'Renewal'];

        $l = $isFr ? [
            'invoice' => 'Facture', 'issuedAt' => 'Émise le', 'dueAt' => 'Échéance',
            'paidAt' => 'Payée le', 'invoiceTo' => 'Facturé à', 'billingDetails' => 'Détails',
            'period' => 'Période', 'market' => 'Marché', 'legalStatus' => 'Statut juridique',
            'vat' => 'N° TVA', 'siret' => 'SIRET',
            'description' => 'Description', 'type' => 'Type', 'qty' => 'Qté',
            'unitPrice' => 'Prix unit.', 'total' => 'Total', 'subtotal' => 'Sous-total',
            'tax' => 'Taxes', 'walletCredit' => 'Crédit portefeuille', 'amountDue' => 'Montant dû',
            'notes' => 'Notes', 'dueNotice' => 'En attente de paiement — échéance dépassée',
            'generated' => 'Généré le', 'payments' => 'Paiements', 'creditNotes' => 'Avoirs',
            'amount' => 'Montant', 'status' => 'Statut', 'provider' => 'Fournisseur',
            'date' => 'Date', 'number' => 'Numéro', 'reason' => 'Motif',
            'annexeOf' => 'Annexe de la facture', 'annexes' => 'Annexes',
            'paymentTerms' => 'Conditions de paiement', 'latePenalty' => 'Pénalités de retard',
            'recoveryFee' => 'Indemnité forfaitaire de recouvrement',
        ] : [
            'invoice' => 'Invoice', 'issuedAt' => 'Issued', 'dueAt' => 'Due',
            'paidAt' => 'Paid', 'invoiceTo' => 'Invoice To', 'billingDetails' => 'Details',
            'period' => 'Period', 'market' => 'Market', 'legalStatus' => 'Legal Status',
            'vat' => 'VAT', 'siret' => 'SIRET',
            'description' => 'Description', 'type' => 'Type', 'qty' => 'Qty',
            'unitPrice' => 'Unit Price', 'total' => 'Total', 'subtotal' => 'Subtotal',
            'tax' => 'Tax', 'walletCredit' => 'Wallet Credit', 'amountDue' => 'Amount Due',
            'notes' => 'Notes', 'dueNotice' => 'Payment pending — past due',
            'generated' => 'Generated on', 'payments' => 'Payments', 'creditNotes' => 'Credit Notes',
            'amount' => 'Amount', 'status' => 'Status', 'provider' => 'Provider',
            'date' => 'Date', 'number' => 'Number', 'reason' => 'Reason',
            'annexeOf' => 'Annexe of invoice', 'annexes' => 'Annexes',
            'paymentTerms' => 'Payment terms', 'latePenalty' => 'Late payment penalties',
            'recoveryFee' => 'Fixed recovery compensation',
        ];

        $fmtDate = fn($d) => $d ? \Carbon\Carbon::parse($d)->locale($locale)->isoFormat('D MMM YYYY') : '—';
        $cur = $snap['currency'] ?? $invoice->currency ?? 'EUR';
        // Use regular space (not narrow no-break space U+202F which is missing from most fonts and causes DomPDF font fallback)
        $fmtMoney = fn($cents) => number_format($cents / 100, 2, ',', ' ') . ' ' . $cur;

        $exemptionLabels = $isFr
            ? ['reverse_charge_intra_eu' => 'Autoliquidation TVA intra-UE — art. 283-2 CGI', 'export_extra_eu' => 'Exonération TVA — export hors UE']
            : ['reverse_charge_intra_eu' => 'Reverse charge — intra-EU B2B (art. 283-2 CGI)', 'export_extra_eu' => 'VAT exempt — export outside EU'];

        $isOverdue = $invoice->status === 'overdue' || ($invoice->status === 'open' && $invoice->due_at && $invoice->due_at->isPast());
        $displayStatus = $isOverdue && $invoice->status === 'open' ? 'overdue' : $invoice->status;

        $pf = $platformConfig ?? config('billing.platform', []);
        $pfName = $pf['legal_name'] ?? config('app.name', 'Leezr');
        $pfVat = $pf['vat_number'] ?? null;
        $pfSiret = $pf['siret'] ?? null;
        $pfRcs = $pf['rcs'] ?? null;
        $pfCapital = $pf['capital'] ?? null;
        $pfAddress = $pf['address'] ?? null;
        $pfEmail = $pf['email'] ?? null;

        $billingPolicy = \App\Core\Billing\PlatformBillingPolicy::first();
        $dueDays = $billingPolicy?->invoice_due_days ?? 30;
    @endphp

    <div class="card">
        {{-- ─── Header ─── --}}
        <table class="header-table">
            <tr>
                <td class="header-left">
                    <div class="logo">{{ strtolower(config('app.name', 'leezr')) }}<span class="dot">.</span></div>
                    @if($pfAddress)<p class="detail" style="margin-top: 4px;">{{ $pfAddress }}</p>@endif
                    @if($pfEmail)<p class="detail">{{ $pfEmail }}</p>@endif
                    @if($pfVat)<p class="detail">{{ $l['vat'] }} : {{ $pfVat }}</p>@endif
                </td>
                <td class="header-right">
                    <div class="invoice-number">{{ $invoice->displayNumber() ?: $invoice->number }}</div>
                    <span class="status status-{{ $displayStatus }}">{{ $statusLabels[$displayStatus] ?? ucfirst($displayStatus) }}</span>
                    <div class="meta" style="margin-top: 6px;">
                        <p>{{ $l['issuedAt'] }} : {{ $fmtDate($invoice->issued_at) }}</p>
                        <p>{{ $l['dueAt'] }} : {{ $fmtDate($invoice->due_at) }}</p>
                        @if($invoice->paid_at)<p>{{ $l['paidAt'] }} : {{ $fmtDate($invoice->paid_at) }}</p>@endif
                    </div>
                </td>
            </tr>
        </table>

        @if($isOverdue && $invoice->status !== 'paid')
        <div class="due-notice">{{ $l['dueNotice'] }}</div>
        @endif

        {{-- ─── Billing: details LEFT, recipient RIGHT (envelope window) ─── --}}
        <table class="billing-table">
            <tr>
                <td>
                    <div class="section-title">{{ $l['billingDetails'] }}</div>
                    <table class="details-inner">
                        @if($invoice->period_start && $invoice->period_end)
                        <tr><td class="lbl">{{ $l['period'] }}</td><td class="val">{{ $fmtDate($invoice->period_start) }} – {{ $fmtDate($invoice->period_end) }}</td></tr>
                        @endif
                        <tr><td class="lbl">{{ $l['dueAt'] }}</td><td class="val">{{ $fmtDate($invoice->due_at) }}</td></tr>
                        @if(!empty($snap['market_name']))
                        <tr><td class="lbl">{{ $l['market'] }}</td><td class="val">{{ $snap['market_name'] }}</td></tr>
                        @endif
                        @if(!empty($snap['legal_status_name']))
                        <tr><td class="lbl">{{ $l['legalStatus'] }}</td><td class="val">{{ $snap['legal_status_name'] }}</td></tr>
                        @endif
                    </table>
                </td>
                <td style="padding-left: 50px;">
                    <div class="section-title">{{ $l['invoiceTo'] }}</div>
                    <p class="legal-name">{{ $snap['company_legal_name'] ?? $snap['company_name'] ?? $company->name }}</p>
                    @if(!empty($snap['billing_address']))<p class="detail">{{ $snap['billing_address'] }}</p>@endif
                    @if(!empty($snap['billing_email']))<p class="detail">{{ $snap['billing_email'] }}</p>@endif
                    @if(!empty($snap['vat_number']))<p class="detail">{{ $l['vat'] }} : {{ $snap['vat_number'] }}</p>@endif
                    @if(!empty($snap['siret']))<p class="detail">{{ $l['siret'] }} : {{ $snap['siret'] }}</p>@endif
                </td>
            </tr>
        </table>

        {{-- ─── Line items ─── --}}
        <table class="items">
            <thead><tr>
                <th>{{ $l['description'] }}</th>
                <th>{{ $l['type'] }}</th>
                <th class="text-center">{{ $l['qty'] }}</th>
                <th class="text-end">{{ $l['unitPrice'] }}</th>
                <th class="text-end">{{ $l['total'] }}</th>
            </tr></thead>
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

        {{-- ─── Totals ─── --}}
        <table class="totals-table">
            <tr>
                <td class="note-col">
                    @if($invoice->notes)<p><strong>{{ $l['notes'] }} :</strong> {{ $invoice->notes }}</p>@endif
                </td>
                <td class="amounts-col">
                    @php $fi = "font-family: {$fontCss};"; @endphp
                    <table class="amounts-inner">
                        <tr><td class="lbl" style="{!! $fi !!}">{{ $l['subtotal'] }}</td><td class="val" style="{!! $fi !!}">{{ $fmtMoney($invoice->subtotal) }}</td></tr>
                        @if($invoice->tax_amount > 0)
                        <tr><td class="lbl" style="{!! $fi !!}">{{ $l['tax'] }} ({{ number_format($invoice->tax_rate_bps / 100, 2) }}%)</td><td class="val" style="{!! $fi !!}">{{ $fmtMoney($invoice->tax_amount) }}</td></tr>
                        @elseif($invoice->tax_exemption_reason)
                        <tr><td class="lbl" style="{!! $fi !!}">{{ $l['tax'] }}</td><td class="val" style="{!! $fi !!}">{{ $fmtMoney(0) }}</td></tr>
                        @endif
                        @if($invoice->wallet_credit_applied > 0)
                        <tr><td class="lbl" style="{!! $fi !!}">{{ $l['walletCredit'] }}</td><td class="val credit" style="{!! $fi !!}">-{{ $fmtMoney($invoice->wallet_credit_applied) }}</td></tr>
                        @endif
                        <tr class="sep"><td colspan="2"></td></tr>
                        <tr><td class="total-lbl" style="{!! $fi !!}">{{ $l['total'] }}</td><td class="total-val" style="{!! $fi !!}">{{ $fmtMoney($invoice->amount) }}</td></tr>
                        @if($invoice->amount_due !== $invoice->amount)
                        <tr><td class="due-lbl" style="{!! $fi !!}">{{ $l['amountDue'] }}</td><td class="due-val" style="{!! $fi !!}">{{ $fmtMoney($invoice->amount_due) }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        @if($invoice->tax_exemption_reason && isset($exemptionLabels[$invoice->tax_exemption_reason]))
        <div class="info-box info-blue">{{ $exemptionLabels[$invoice->tax_exemption_reason] }}</div>
        @endif

        @if($invoice->isAnnexe() && $invoice->parentInvoice)
        <div class="info-box">{{ $l['annexeOf'] }} : <strong>{{ $invoice->parentInvoice->number }}</strong></div>
        @endif

        @if($invoice->annexes && $invoice->annexes->count() > 0)
        <div class="info-box"><strong>{{ $l['annexes'] }} :</strong> @foreach($invoice->annexes as $annexe){{ $annexe->displayNumber() }}@if(!$loop->last), @endif @endforeach</div>
        @endif

        {{-- ═══ PAYMENTS ════════════════════════════════════ --}}
        @if((isset($payments) && $payments->count() > 0) || $invoice->wallet_credit_applied > 0)
        <div class="section-title">{{ $l['payments'] }}</div>
        <table class="items">
            <thead><tr>
                <th>{{ $l['amount'] }}</th>
                <th>{{ $l['status'] }}</th>
                <th>{{ $l['provider'] }}</th>
                <th>{{ $l['date'] }}</th>
            </tr></thead>
            <tbody>
                {{-- ADR-334: Wallet credit as payment entry --}}
                @if($invoice->wallet_credit_applied > 0)
                @php
                    $walletPayStatusLabels = $isFr
                        ? ['succeeded' => 'Réussi']
                        : ['succeeded' => 'Succeeded'];
                    $walletProviderLabel = $isFr ? 'Crédit portefeuille' : 'Wallet credit';
                    $walletDate = $invoice->paid_at ?? $invoice->finalized_at;
                @endphp
                <tr>
                    <td>{{ $fmtMoney($invoice->wallet_credit_applied) }}</td>
                    <td><span class="type-chip">{{ $walletPayStatusLabels['succeeded'] }}</span></td>
                    <td>{{ $walletProviderLabel }}</td>
                    <td>{{ $walletDate ? $fmtDate($walletDate) : '—' }}</td>
                </tr>
                {{-- ADR-335: Wallet credit FIFO breakdown --}}
                @foreach(($walletSources ?? []) as $src)
                <tr>
                    <td style="padding-left:20px; font-size:9px; color:#999;">{{ $fmtMoney($src['amount']) }}</td>
                    <td style="font-size:9px; color:#999;"></td>
                    <td style="font-size:9px; color:#999;">{{ $src['description'] ?? '—' }}</td>
                    <td style="font-size:9px; color:#999;">{{ isset($src['created_at']) ? $fmtDate($src['created_at']) : '' }}</td>
                </tr>
                @endforeach
                @endif
                @foreach(($payments ?? collect()) as $payment)
                @php
                    $payStatusLabels = $isFr
                        ? ['succeeded' => 'Réussi', 'failed' => 'Échoué', 'pending' => 'En attente', 'refunded' => 'Remboursé']
                        : ['succeeded' => 'Succeeded', 'failed' => 'Failed', 'pending' => 'Pending', 'refunded' => 'Refunded'];
                @endphp
                <tr>
                    <td>{{ $fmtMoney($payment->amount) }}</td>
                    <td><span class="type-chip">{{ $payStatusLabels[$payment->status] ?? ucfirst($payment->status) }}</span></td>
                    <td>{{ ucfirst($payment->provider ?? '—') }}</td>
                    <td>{{ $fmtDate($payment->created_at) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        {{-- ═══ CREDIT NOTES ════════════════════════════════ --}}
        @if($invoice->creditNotes && $invoice->creditNotes->count() > 0)
        <div class="section-title">{{ $l['creditNotes'] }}</div>
        <table class="items">
            <thead><tr>
                <th>{{ $l['number'] }}</th>
                <th>{{ $l['amount'] }}</th>
                <th>{{ $l['status'] }}</th>
                <th>{{ $l['reason'] }}</th>
                <th>{{ $l['date'] }}</th>
            </tr></thead>
            <tbody>
                @foreach($invoice->creditNotes as $cn)
                <tr>
                    <td>{{ $cn->number ?? '—' }}</td>
                    <td>{{ $fmtMoney($cn->amount) }}</td>
                    <td><span class="type-chip">{{ ucfirst($cn->status) }}</span></td>
                    <td>{{ $cn->reason ?? '—' }}</td>
                    <td>{{ $fmtDate($cn->issued_at ?? $cn->created_at) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    {{-- ═══ FOOTER ══════════════════════════════════════ --}}
    <div class="footer">
        <div class="footer-main">
            <strong>{{ $pfName }}@if($pfSiret) — SIRET : {{ $pfSiret }}@endif @if($pfRcs) — RCS : {{ $pfRcs }}@endif @if($pfCapital) — Capital : {{ $pfCapital }}@endif @if($pfVat) — TVA : {{ $pfVat }}@endif</strong>
            @if($pfAddress) — {{ $pfAddress }}@endif @if($pfEmail) — {{ $pfEmail }}@endif
            @if($isFr)
            — {{ $l['paymentTerms'] }} : {{ $dueDays }} jours. {{ $l['latePenalty'] }} : taux BCE + 10 pts (art. L441-10). {{ $l['recoveryFee'] }} : 40 EUR (art. D441-5).
            @else
            — {{ $l['paymentTerms'] }}: {{ $dueDays }} days. {{ $l['latePenalty'] }}: ECB rate + 10 pts (art. L441-10). {{ $l['recoveryFee'] }}: 40 EUR (art. D441-5).
            @endif
        </div>
        <div class="footer-generated">{{ $l['generated'] }} {{ now()->locale($locale)->isoFormat('D MMMM YYYY [à] HH:mm') }}</div>
    </div>

    {{-- Page numbering: bottom-right of every page --}}
    <script type="text/php">
        if (isset($pdf)) {
            $w = $pdf->get_width();
            $h = $pdf->get_height();
            $font = $fontMetrics->getFont('{!! addslashes(strtolower($fontFamily ?? 'dejavu sans')) !!}', 'normal');
            if (!$font) $font = $fontMetrics->getFont('dejavu sans', 'normal');
            $pdf->page_text($w - 38, $h - 24, "{PAGE_NUM}/{PAGE_COUNT}", $font, 8, [0.65, 0.64, 0.68]);
        }
    </script>
</body>
</html>
