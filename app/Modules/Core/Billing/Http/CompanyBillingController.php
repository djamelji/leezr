<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CompanyBillingController
{
    public function overview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(array_merge(
            CompanyBillingReadService::overview($company),
            ['pending_subscription' => CompanyBillingReadService::pendingSubscription($company)],
        ));
    }

    public function invoices(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $filters = $request->only(['status', 'from', 'to']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json(
            CompanyBillingReadService::invoices($company, $filters, $perPage),
        );
    }

    public function invoiceDetail(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $invoice = CompanyBillingReadService::invoiceDetail($company, $id);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json(['invoice' => $invoice]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'subscription' => CompanyBillingReadService::currentSubscription($company),
            'pending_subscription' => CompanyBillingReadService::pendingSubscription($company),
        ]);
    }

    /**
     * Dismiss a rejected subscription so the company can request again (ADR-289).
     */
    public function dismissPendingSubscription(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $deleted = Subscription::where('company_id', $company->id)
            ->where('status', 'rejected')
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No rejected subscription to dismiss.'], 404);
        }

        return response()->json(['message' => 'Rejected subscription dismissed.']);
    }

    public function nextInvoicePreview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $preview = CompanyBillingReadService::nextInvoicePreview($company);

        if (!$preview) {
            return response()->json(['preview' => null]);
        }

        return response()->json(['preview' => $preview]);
    }

    public function planChangePreview(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'to_plan_key' => ['required', 'string', 'in:' . implode(',', PlanRegistry::keys())],
            'to_interval' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $preview = CompanyBillingReadService::planChangePreview(
            $company,
            $validated['to_plan_key'],
            $validated['to_interval'] ?? PlatformBillingPolicy::instance()->default_billing_interval,
        );

        if (!$preview) {
            return response()->json(['message' => 'No active subscription or invalid plan.'], 422);
        }

        return response()->json(['preview' => $preview]);
    }

    public function invoicePdf(Request $request, int $id): Response
    {
        $company = $request->attributes->get('company');

        $invoice = Invoice::where('company_id', $company->id)
            ->where('id', $id)
            ->whereNotNull('finalized_at')
            ->with(['lines', 'company', 'parentInvoice', 'annexes', 'creditNotes'])
            ->first();

        if (!$invoice) {
            abort(404, 'Invoice not found.');
        }

        $snap = $invoice->billing_snapshot ?? [];
        $locale = $snap['market_locale'] ?? 'fr-FR';

        // ADR-328 S9: Load payments for PDF rendering
        // ADR-335: Exclude failed payments from PDF (visible on page only)
        $payments = \App\Core\Billing\Payment::where('invoice_id', $invoice->id)
            ->where('status', '!=', 'failed')
            ->get();

        // Resolve platform typography for font embedding in PDF.
        // DomPDF needs TTF (not WOFF2). For Google Fonts, fetch the CSS with
        // an old user-agent so Google serves TTF @font-face declarations.
        try {
            $typography = \App\Core\Typography\TypographyResolverService::forPlatform();
        } catch (\Throwable) {
            $typography = ['active_family_name' => null, 'active_source' => null];
        }

        $fontFaceCss = null;
        $fontFamily = $typography['active_family_name'] ?? null;
        $fontSource = $typography['active_source'] ?? null;

        if ($fontFamily && $fontSource === 'google') {
            try {
                $url = 'https://fonts.googleapis.com/css2?family='
                    . urlencode($fontFamily) . ':wght@300;400;500;600;700&display=swap';
                $ctx = stream_context_create([
                    'http' => ['header' => "User-Agent: Mozilla/4.0\r\n", 'timeout' => 5],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $fontFaceCss = @file_get_contents($url, false, $ctx);
                if ($fontFaceCss === false) {
                    $fontFaceCss = null;
                }
            } catch (\Throwable) {
                $fontFaceCss = null;
            }
        }

        // Resolve platform primary color for PDF branding
        try {
            $primaryColor = \App\Core\Theme\UIResolverService::forPlatform()->primaryColor;
        } catch (\Throwable) {
            $primaryColor = '#7367F0';
        }

        // ADR-335: Wallet credit FIFO breakdown for PDF (with fallback for pre-ADR-335 invoices)
        $walletSources = CompanyBillingReadService::walletCreditSources($invoice);

        $viewData = [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'snap' => $snap,
            'locale' => $locale,
            'payments' => $payments,
            'walletSources' => $walletSources,
            'typography' => $typography,
            'fontFaceCss' => $fontFaceCss,
            'primaryColor' => $primaryColor,
            'platformConfig' => config('billing.platform'),
        ];

        // Render PDF with font fallback: if custom font fails (network, cache),
        // retry with safe DejaVu Sans to guarantee PDF generation.
        try {
            $pdfContent = \Barryvdh\DomPDF\Facade\Pdf::loadView('billing.invoice-pdf', $viewData)->output();
        } catch (\Throwable) {
            $viewData['typography'] = ['active_family_name' => null, 'active_source' => null];
            $pdfContent = \Barryvdh\DomPDF\Facade\Pdf::loadView('billing.invoice-pdf', $viewData)->output();
        }

        $filename = ($invoice->number ?: "invoice-{$invoice->id}") . '.pdf';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
