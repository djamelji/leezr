<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; color: #333; padding: 40px; max-width: 800px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; border-bottom: 2px solid #7367f0; padding-bottom: 20px; }
        .header h1 { font-size: 28px; color: #7367f0; }
        .header .invoice-meta { text-align: right; }
        .header .invoice-meta p { margin: 4px 0; }
        .section { margin-bottom: 24px; }
        .section h3 { font-size: 14px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f5f5f9; text-align: left; padding: 10px 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #666; }
        td { padding: 10px 12px; border-bottom: 1px solid #eee; }
        .amount { text-align: right; }
        .totals { margin-top: 20px; }
        .totals table { width: 300px; margin-left: auto; }
        .totals td { padding: 6px 12px; }
        .totals .total-row { font-weight: bold; font-size: 16px; border-top: 2px solid #333; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .status-open { background: #e3f2fd; color: #1565c0; }
        .status-paid { background: #e8f5e9; color: #2e7d32; }
        .status-overdue { background: #ffebee; color: #c62828; }
        .status-voided { background: #fff3e0; color: #e65100; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #888; text-align: center; }
        @media print { body { padding: 20px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Invoice</h1>
            <p><strong>{{ $invoice->number }}</strong></p>
        </div>
        <div class="invoice-meta">
            <p><span class="status status-{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span></p>
            <p>Issued: {{ $invoice->issued_at?->format('M d, Y') ?? '—' }}</p>
            <p>Due: {{ $invoice->due_at?->format('M d, Y') ?? '—' }}</p>
            @if($invoice->paid_at)
                <p>Paid: {{ $invoice->paid_at->format('M d, Y') }}</p>
            @endif
        </div>
    </div>

    <div class="section">
        <h3>Bill To</h3>
        <p><strong>{{ $company->name }}</strong></p>
        @if($invoice->period_start && $invoice->period_end)
            <p>Period: {{ $invoice->period_start->format('M d, Y') }} &mdash; {{ $invoice->period_end->format('M d, Y') }}</p>
        @endif
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th class="amount">Unit Price</th>
                    <th class="amount">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->type }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td class="amount">{{ number_format($line->unit_amount / 100, 2) }} {{ $invoice->currency }}</td>
                    <td class="amount">{{ number_format($line->amount / 100, 2) }} {{ $invoice->currency }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="totals">
        <table>
            <tr>
                <td>Subtotal</td>
                <td class="amount">{{ number_format($invoice->subtotal / 100, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @if($invoice->tax_amount > 0)
            <tr>
                <td>Tax ({{ number_format($invoice->tax_rate_bps / 100, 2) }}%)</td>
                <td class="amount">{{ number_format($invoice->tax_amount / 100, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endif
            <tr>
                <td><strong>Total</strong></td>
                <td class="amount"><strong>{{ number_format($invoice->amount / 100, 2) }} {{ $invoice->currency }}</strong></td>
            </tr>
            @if($invoice->wallet_credit_applied > 0)
            <tr>
                <td>Wallet Credit Applied</td>
                <td class="amount">-{{ number_format($invoice->wallet_credit_applied / 100, 2) }} {{ $invoice->currency }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Amount Due</td>
                <td class="amount">{{ number_format($invoice->amount_due / 100, 2) }} {{ $invoice->currency }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <p>Generated on {{ now()->format('M d, Y \a\t H:i') }}</p>
    </div>
</body>
</html>
