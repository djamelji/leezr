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

        // ADR-626 + finition (ADR-627): keep the PREMIUM typography (Poppins, OFL 1.1) but load it
        // from the LOCAL font cache (storage/fonts/*.ttf) instead of a blocking per-render Google
        // fetch — premium look, zero network dependency. Font subsetting keeps the file light.
        // Falls back to the bundled DejaVu Sans (French accents + €) if the local TTFs are absent.
        $fontFamily = $typography['active_family_name'] ?? null;
        $fontSource = $typography['active_source'] ?? null;
        $fontFaceCss = $fontFamily ? static::localFontFaceCss($fontFamily) : null;

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

        // ADR-626 (BILL-PERF-PDF): font subsetting embeds only the glyphs actually used → a much
        // lighter PDF (instead of the full ~800KB DejaVu face). No network, deterministic output.
        // ADR-627 fix: on atomic deploys `storage/` is a symlink OUTSIDE base_path (the dompdf
        // chroot), so dompdf cannot write the subset-font cache there (fwrite false). Point the font
        // cache + temp dir to a writable path INSIDE the release (bootstrap/cache), and widen the
        // chroot to also allow reading the bundled TTFs + the storage cache.
        $fontCacheDir = base_path('bootstrap/cache/dompdf-fonts');
        if (! is_dir($fontCacheDir)) {
            @mkdir($fontCacheDir, 0775, true);
        }
        $chroot = array_values(array_unique(array_filter([
            realpath(base_path()),
            realpath(storage_path()),
            $fontCacheDir,
        ])));

        $render = fn (array $data) => \Barryvdh\DomPDF\Facade\Pdf::loadView('billing.invoice-pdf', $data)
            ->setOption('isFontSubsettingEnabled', true)
            ->setOption('fontDir', $fontCacheDir)
            ->setOption('fontCache', $fontCacheDir)
            ->setOption('tempDir', $fontCacheDir)
            ->setOption('chroot', $chroot)
            ->output();

        try {
            $pdfContent = $render($viewData);
        } catch (\Throwable) {
            $viewData['typography'] = ['active_family_name' => null, 'active_source' => null];
            $pdfContent = $render($viewData);
        }

        $filename = ($invoice->number ?: "invoice-{$invoice->id}") . '.pdf';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * ADR-627: build @font-face CSS from the LOCALLY cached TTF files (storage/fonts/{family}_*.ttf),
     * so the premium platform font (e.g. Poppins, OFL 1.1) is embedded WITHOUT any network request.
     * Returns null if no local TTF is found → the blade falls back to the bundled DejaVu Sans.
     */
    protected static function localFontFaceCss(string $family): ?string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $family));
        $weightMap = ['normal' => 400, 'regular' => 400, 'bold' => 700];

        // 1) Prefer the fonts BUNDLED in the repo (resources/fonts/{slug}/{slug}-{weight}.ttf) — always
        //    deployed with the app. 2) Fall back to the dompdf local cache (storage/fonts/{slug}_*.ttf).
        $bundled = glob(base_path("resources/fonts/{$slug}/{$slug}-*.ttf")) ?: [];
        $cached = glob(storage_path("fonts/{$slug}_*.ttf")) ?: [];
        $files = ! empty($bundled) ? $bundled : $cached;
        if (empty($files)) {
            return null;
        }

        $css = '';
        foreach ($files as $path) {
            $base = basename($path);
            if (preg_match('/-([a-z0-9]+)\.ttf$/i', $base, $m)) {          // bundled: poppins-700.ttf
                $token = strtolower($m[1]);
            } elseif (preg_match('/_([a-z0-9]+)_[0-9a-f]+\.ttf$/i', $base, $m)) { // cache: poppins_bold_hash.ttf
                $token = strtolower($m[1]);
            } else {
                continue;
            }
            $weight = $weightMap[$token] ?? (ctype_digit($token) ? (int) $token : 400);
            $css .= "@font-face{font-family:'{$family}';font-style:normal;font-weight:{$weight};src:url('file://{$path}') format('truetype');}";
        }

        return $css !== '' ? $css : null;
    }
}
