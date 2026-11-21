<?php

namespace App\Core\Billing\Documents;

use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\CompanyBillingReadService;

/**
 * ADR-848 (Leezr 4.2 M9) — THE invoice PDF. Moved verbatim from CompanyBillingController::invoicePdf (ADR-328/335/
 * 626/627 : payments without failed ones, wallet credit sources, platform typography from LOCAL fonts, subsetting,
 * dompdf chroot/cache inside the release). Platform used to render the same view WITHOUT these (failed payments
 * included, no typography, full font face) : both now render the same document. Presentation only — no amount is
 * computed here (Billing engine untouched).
 */
class InvoicePdfRenderer
{
    /** @param Invoice $invoice finalized, with lines, company, parentInvoice, annexes, creditNotes loaded */
    public static function render(Invoice $invoice): string
    {
        $snap = $invoice->billing_snapshot ?? [];
        $locale = $snap['market_locale'] ?? 'fr-FR';

        // ADR-328 S9: Load payments for PDF rendering
        // ADR-335: Exclude failed payments from PDF (visible on page only)
        $payments = \App\Core\Billing\Payment::where('invoice_id', $invoice->id)
            ->where('status', '!=', 'failed')
            ->get();

        // Resolve platform typography for font embedding in PDF (local TTF only — see ADR-627 below).
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
        $fontFaceCss = $fontFamily ? self::localFontFaceCss($fontFamily) : null;

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
            return $render($viewData);
        } catch (\Throwable) {
            $viewData['typography'] = ['active_family_name' => null, 'active_source' => null];
            return $render($viewData);
        }

    }

    public static function filename(Invoice $invoice): string
    {
        return ($invoice->number ?: "invoice-{$invoice->id}").'.pdf';
    }

    /**
     * ADR-627: build @font-face CSS from the LOCALLY cached TTF files (storage/fonts/{family}_*.ttf),
     * so the premium platform font (e.g. Poppins, OFL 1.1) is embedded WITHOUT any network request.
     * Returns null if no local TTF is found → the blade falls back to the bundled DejaVu Sans.
     */
    public static function localFontFaceCss(string $family): ?string
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
