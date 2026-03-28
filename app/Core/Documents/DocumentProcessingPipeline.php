<?php

namespace App\Core\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Process;

class DocumentProcessingPipeline
{
    private array $tempFiles = [];

    public function __construct(
        private readonly ImageProcessor $imageProcessor,
    ) {}

    /**
     * Process uploaded files: orient/deskew/trim images, merge all into single PDF, OCR.
     * For PDFs: convert pages to images → process → re-merge → OCR.
     *
     * @param  UploadedFile[]  $files
     */
    public function process(array $files, string $baseName): ProcessingResult
    {
        try {
            $processedImages = [];
            $pdfSources = [];

            foreach ($files as $file) {
                $mime = $file->getMimeType();

                if ($this->imageProcessor->isImage($mime)) {
                    $tempInput = $this->saveTempFile($file);
                    $processed = $this->imageProcessor->processImage($tempInput);
                    $processedImages[] = $processed;
                    $pdfSources[] = ['type' => 'image', 'path' => $processed];

                    if ($processed !== $tempInput) {
                        $this->tempFiles[] = $processed;
                    }
                } elseif ($mime === 'application/pdf') {
                    $tempPdf = $this->saveTempFile($file);

                    // Try to convert PDF pages to images for processing + OCR
                    $pageImages = $this->imageProcessor->pdfToImages($tempPdf);

                    if (! empty($pageImages)) {
                        // PDF successfully rasterized → process each page image
                        foreach ($pageImages as $pageImg) {
                            $this->tempFiles[] = $pageImg;
                            $processed = $this->imageProcessor->processImage($pageImg);
                            $processedImages[] = $processed;
                            $pdfSources[] = ['type' => 'image', 'path' => $processed];

                            if ($processed !== $pageImg) {
                                $this->tempFiles[] = $processed;
                            }
                        }
                    } else {
                        // Conversion failed (fake PDF, encrypted, etc.) → passthrough
                        $pdfSources[] = ['type' => 'passthrough', 'path' => $tempPdf];
                    }
                } else {
                    $tempInput = $this->saveTempFile($file);
                    $pdfSources[] = ['type' => 'passthrough', 'path' => $tempInput];
                }
            }

            // If all sources are passthrough, return original file as-is
            $allPassthrough = collect($pdfSources)->every(fn ($s) => $s['type'] === 'passthrough');
            if ($allPassthrough && count($pdfSources) === 1) {
                $path = $pdfSources[0]['path'];
                $ext = pathinfo($files[0]->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'pdf';

                return new ProcessingResult(
                    pdfPath: $path,
                    fileSize: filesize($path),
                    fileName: $baseName.'.'.$ext,
                    ocrText: null,
                    pageCount: 1,
                    mimeType: $files[0]->getMimeType() ?: 'application/pdf',
                    passthrough: true,
                );
            }

            // Merge into single PDF
            $pdfPath = $this->mergeToPdf($pdfSources, $baseName);
            $this->tempFiles[] = $pdfPath;

            // OCR on all processed images (from photos AND from PDF pages)
            $ocrText = $this->extractOcrText($processedImages);

            $pageCount = $this->countPdfPages($pdfPath);

            Log::info('DocumentProcessingPipeline: processed', [
                'baseName' => $baseName,
                'images' => count($processedImages),
                'pages' => $pageCount,
                'ocrChars' => $ocrText ? strlen($ocrText) : 0,
                'fileSize' => filesize($pdfPath),
            ]);

            return new ProcessingResult(
                pdfPath: $pdfPath,
                fileSize: filesize($pdfPath),
                fileName: $baseName.'.pdf',
                ocrText: $ocrText,
                pageCount: $pageCount,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DocumentProcessingPipeline: processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'baseName' => $baseName,
                'magick' => $this->imageProcessor->findMagick(),
            ]);

            $detail = app()->isLocal() || config('app.debug')
                ? ' ('.$e->getMessage().')'
                : '';

            throw ValidationException::withMessages([
                'files' => [__('documents.mergeFailed').$detail],
            ]);
        }
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function saveTempFile(UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'tmp';
        $path = sys_get_temp_dir().'/'.uniqid('doc_').'.'.$ext;
        copy($file->getRealPath(), $path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<array{type: string, path: string}>  $sources
     */
    private function mergeToPdf(array $sources, string $baseName): string
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);

        foreach ($sources as $source) {
            if ($source['type'] === 'image') {
                $this->addImagePage($pdf, $source['path']);
            } elseif ($source['type'] === 'passthrough' && str_ends_with(strtolower($source['path']), '.pdf')) {
                $this->importPdfPages($pdf, $source['path']);
            }
        }

        $outputPath = sys_get_temp_dir().'/'.uniqid('merged_').'_'.$baseName.'.pdf';
        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    private function addImagePage(Fpdi $pdf, string $imagePath): void
    {
        $info = @getimagesize($imagePath);
        if (! $info) {
            Log::warning('DocumentProcessingPipeline: cannot read image dimensions', ['path' => $imagePath]);

            return;
        }

        [$imgW, $imgH] = $info;

        // ADR-410: Adaptive page size — page matches image dimensions + margin
        $dpi = 200;
        $pxToMm = 25.4 / $dpi;
        $margin = 5; // 5mm margin around the document

        $drawW = $imgW * $pxToMm;
        $drawH = $imgH * $pxToMm;

        $pageW = $drawW + (2 * $margin);
        $pageH = $drawH + (2 * $margin);

        $orientation = ($pageW > $pageH) ? 'L' : 'P';

        $pdf->AddPage($orientation, [$pageW, $pageH]);
        $pdf->Image($imagePath, $margin, $margin, $drawW, $drawH);
    }

    private function importPdfPages(Fpdi $pdf, string $pdfPath): void
    {
        try {
            $pageCount = $pdf->setSourceFile($pdfPath);
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }
        } catch (\Throwable $e) {
            Log::warning('DocumentProcessingPipeline: cannot import PDF', [
                'path' => $pdfPath,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'files' => [__('documents.corruptedPdf')],
            ]);
        }
    }

    private function countPdfPages(string $pdfPath): int
    {
        try {
            $tempPdf = new Fpdi();

            return $tempPdf->setSourceFile($pdfPath);
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * @param  string[]  $imagePaths
     */
    private function extractOcrText(array $imagePaths): ?string
    {
        if (empty($imagePaths)) {
            return null;
        }

        if (! $this->findTesseract()) {
            Log::info('DocumentProcessingPipeline: Tesseract not available, skipping OCR');

            return null;
        }

        $tesseractBin = $this->findTesseract();

        $texts = [];
        foreach ($imagePaths as $path) {
            try {
                // ADR-421: Call Tesseract directly via Process::run() instead of
                // TesseractOCR library — the library's FriendlyErrors::checkTesseractPresence()
                // uses file_exists() which is blocked by ISPConfig open_basedir.
                $result = Process::timeout(15)->run([
                    $tesseractBin, $path, 'stdout', '-l', 'fra+eng',
                ]);

                if ($result->successful()) {
                    $text = trim($result->output());
                    if (! empty($text)) {
                        $texts[] = $text;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('DocumentProcessingPipeline: OCR failed for image', [
                    'correlation_id' => $this->correlationId,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ! empty($texts) ? implode("\n---\n", $texts) : null;
    }

    private function findTesseract(): ?string
    {
        static $path = false;
        if ($path !== false) {
            return $path;
        }

        // Use Process::run() — file_exists() and exec() both fail under open_basedir (ISPConfig)
        $check = Process::run('command -v tesseract');

        return $path = ($check->successful() ? trim($check->output()) : null);
    }
}
