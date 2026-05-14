<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentTemplate;
use Illuminate\Support\Str;

/**
 * Generates PDF from a DocumentTemplate + resolved variables.
 *
 * Uses strict regex parser for variable substitution (never Blade::render).
 * HTML-escapes all values by default for security.
 * Missing variables render as empty string (logged in generation_snapshot).
 */
class DocumentPdfGenerator
{
    /**
     * Generate a PDF and store it.
     *
     * @return array{file_path: string, file_size_bytes: int}
     */
    public function generate(DocumentTemplate $template, array $variables, int $companyId): array
    {
        // 1. Render HTML with variables
        $html = $this->renderHtml($template, $variables);

        // 2. Generate PDF via dompdf
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        $pdf->setPaper($template->paper_size ?? 'a4', $template->paper_orientation ?? 'portrait');

        $pdfContent = $pdf->output();

        // 3. Store in structured path
        $disk = config('documents.generated_disk', 'local');
        $date = now();
        $uuid = Str::uuid();
        $relativePath = "documents/generated/{$companyId}/{$date->format('Y')}/{$date->format('m')}/{$uuid}.pdf";

        \Illuminate\Support\Facades\Storage::disk($disk)->put($relativePath, $pdfContent);

        return [
            'file_path' => $relativePath,
            'file_size_bytes' => strlen($pdfContent),
        ];
    }

    /**
     * Render the complete HTML document from template parts + variables.
     */
    public function renderHtml(DocumentTemplate $template, array $variables): string
    {
        $header = $this->substituteVariables($template->header_html ?? '', $variables);
        $body = $this->substituteVariables($template->body_html ?? '', $variables);
        $footer = $this->substituteVariables($template->footer_html ?? '', $variables);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                @page { margin: 20mm 15mm 25mm 15mm; }
                body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; line-height: 1.5; color: #333; }
                .header { margin-bottom: 20px; }
                .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9pt; color: #888; text-align: center; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 6px 8px; text-align: left; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .font-bold { font-weight: bold; }
                .mt-4 { margin-top: 16px; }
                .mb-4 { margin-bottom: 16px; }
            </style>
        </head>
        <body>
            <div class="header">{$header}</div>
            <div class="body">{$body}</div>
            <div class="footer">{$footer}</div>
        </body>
        </html>
        HTML;
    }

    /**
     * Substitute {{ variable }} placeholders with HTML-escaped values.
     *
     * Uses strict regex: /\{\{\s*([\w.]+)\s*\}\}/
     * - Only word chars and dots allowed in variable names
     * - HTML-escapes all values by default
     * - Missing variables → empty string
     */
    public function substituteVariables(string $html, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            $value = $variables[$key] ?? '';

            return e($value); // HTML-escape via Laravel helper
        }, $html);
    }
}
