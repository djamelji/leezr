<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\Invoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/platform/billing/invoices/export
 *
 * CSV export of invoices with same filters as the list endpoint (ADR-315).
 */
class BillingExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $query = Invoice::with('company:id,name,slug')
            ->whereNotNull('finalized_at')
            ->orderByDesc('issued_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from')) {
            $query->where('issued_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('issued_at', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="invoices-export-' . now()->format('Y-m-d') . '.csv"',
        ];

        return new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, [
                'Number',
                'Company',
                'Status',
                'Subtotal',
                'Tax Amount',
                'Amount',
                'Amount Due',
                'Issued At',
                'Due At',
                'Paid At',
            ]);

            // Stream data in chunks
            $query->chunkById(200, function ($invoices) use ($handle) {
                foreach ($invoices as $invoice) {
                    fputcsv($handle, [
                        $invoice->number,
                        $invoice->company?->name ?? '',
                        $invoice->status,
                        $invoice->subtotal / 100,
                        $invoice->tax_amount / 100,
                        $invoice->amount / 100,
                        $invoice->amount_due / 100,
                        $invoice->issued_at?->toDateString(),
                        $invoice->due_at?->toDateString(),
                        $invoice->paid_at?->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
