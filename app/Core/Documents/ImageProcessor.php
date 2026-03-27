<?php

namespace App\Core\Documents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ImageProcessor
{
    /**
     * Process an image: detect+crop document → auto-orient → deskew → quality.
     * Returns path to processed image (temp file).
     * Falls back to original if processing fails.
     */
    public function processImage(string $inputPath): string
    {
        $magick = $this->findMagick();
        if (! $magick) {
            Log::warning('ImageProcessor: magick binary not found, using original image', ['input' => $inputPath]);

            return $inputPath;
        }

        // Step 1: Smart crop via Python OpenCV detector
        $cropped = $this->detectAndCrop($inputPath);

        // Step 2: Apply EXIF auto-orient first (so detectOrientation sees the real visual orientation)
        $oriented = sys_get_temp_dir().'/'.uniqid('img_oriented_').'.jpg';
        $result = Process::timeout(15)->env($this->shellEnv())->run([
            $magick, $cropped, '-auto-orient', '-quality', '95', $oriented,
        ]);

        if ($result->failed() || ! file_exists($oriented)) {
            @unlink($oriented);
            $oriented = $cropped;
        } elseif ($cropped !== $inputPath) {
            @unlink($cropped);
        }

        // Step 3: Auto-orientation by OCR score (on EXIF-corrected image)
        $rotation = $this->detectOrientation($oriented);

        // Step 4: Final processing (rotate if needed, deskew, quality)
        $outputPath = sys_get_temp_dir().'/'.uniqid('img_processed_').'.jpg';

        $cmd = [$magick, $oriented];

        if ($rotation > 0) {
            $cmd[] = '-rotate';
            $cmd[] = (string) $rotation;
        }

        $cmd = array_merge($cmd, [
            '-deskew', '40%',
            '-quality', '92',
            $outputPath,
        ]);

        $result = Process::timeout(30)->env($this->shellEnv())->run($cmd);

        if ($result->failed()) {
            Log::warning('ImageProcessor: processing failed, using oriented image', [
                'input' => $inputPath,
                'error' => $result->errorOutput(),
            ]);

            @unlink($outputPath);

            return $oriented;
        }

        // Cleanup intermediate oriented file
        if ($oriented !== $inputPath) {
            @unlink($oriented);
        }

        return $outputPath;
    }

    /**
     * Detect and crop a document from a photo using the Python OpenCV script.
     * Returns path to cropped image, or original path if detection fails/unavailable.
     */
    public function detectAndCrop(string $inputPath): string
    {
        $python = $this->findPython();
        if (! $python) {
            Log::info('ImageProcessor: python3 not found, skipping document detection');

            return $inputPath;
        }

        $scriptPath = base_path('scripts/python/document_detector.py');
        if (! file_exists($scriptPath)) {
            Log::info('ImageProcessor: document_detector.py not found, skipping');

            return $inputPath;
        }

        $outputPath = sys_get_temp_dir().'/'.uniqid('doc_crop_').'.jpg';
        $jsonPath = sys_get_temp_dir().'/'.uniqid('doc_result_').'.json';

        $result = Process::timeout(30)->env($this->shellEnv())->run([
            $python,
            $scriptPath,
            $inputPath,
            $outputPath,
            '--json', $jsonPath,
        ]);

        // Cleanup JSON result file
        $jsonResult = null;
        if (file_exists($jsonPath)) {
            $jsonResult = json_decode(file_get_contents($jsonPath), true);
            @unlink($jsonPath);
        }

        if ($result->failed() || ! file_exists($outputPath)) {
            Log::warning('ImageProcessor: document detection failed', [
                'input' => $inputPath,
                'error' => $result->errorOutput(),
            ]);

            @unlink($outputPath);

            return $inputPath;
        }

        Log::info('ImageProcessor: document detection result', [
            'detected' => $jsonResult['detected'] ?? false,
            'confidence' => $jsonResult['confidence'] ?? 0,
            'width' => $jsonResult['width'] ?? 0,
            'height' => $jsonResult['height'] ?? 0,
        ]);

        return $outputPath;
    }

    /**
     * Detect correct orientation by running OCR at 4 rotations (0, 90, 180, 270).
     * Returns the rotation angle (0, 90, 180, or 270) that produces the most text.
     */
    public function detectOrientation(string $imagePath): int
    {
        try {
            $tesseract = $this->findTesseract();
            $magick = $this->findMagick();

            if (! $tesseract || ! $magick) {
                return 0;
            }

            $bestRotation = 0;
            $bestScore = 0;

            foreach ([0, 90, 180, 270] as $angle) {
                $rotatedPath = $imagePath;

                if ($angle > 0) {
                    $rotatedPath = sys_get_temp_dir().'/'.uniqid("orient_{$angle}_").'.jpg';
                    $result = Process::timeout(10)->env($this->shellEnv())->run([
                        $magick, $imagePath, '-rotate', (string) $angle, $rotatedPath,
                    ]);

                    if ($result->failed()) {
                        @unlink($rotatedPath);

                        continue;
                    }
                }

                $ocrResult = Process::timeout(15)->env($this->shellEnv())->run([
                    $tesseract, $rotatedPath, 'stdout', '-l', 'fra+eng',
                ]);

                if ($angle > 0) {
                    @unlink($rotatedPath);
                }

                if ($ocrResult->failed()) {
                    continue;
                }

                $text = $ocrResult->output();
                $score = preg_match_all('/[a-zA-Z0-9àâäéèêëîïôùûüçÀÂÄÉÈÊËÎÏÔÙÛÜÇ]/', $text);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestRotation = $angle;
                }
            }

            $minThreshold = 10;
            if ($bestScore < $minThreshold) {
                return 0;
            }

            Log::info('ImageProcessor: orientation detected', [
                'rotation' => $bestRotation,
                'score' => $bestScore,
            ]);

            return $bestRotation;
        } catch (\Throwable $e) {
            Log::warning('ImageProcessor: orientation detection failed, skipping', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function findTesseract(): ?string
    {
        // Use command -v only — file_exists() fails under open_basedir (ISPConfig)
        $check = Process::run('command -v tesseract');

        return $check->successful() ? trim($check->output()) : null;
    }

    /**
     * Convert a PDF to individual page images using ImageMagick.
     * Returns array of image paths, or empty array if conversion fails.
     *
     * @return string[]
     */
    public function pdfToImages(string $pdfPath): array
    {
        $magick = $this->findMagick();
        if (! $magick) {
            return [];
        }

        $outputBase = sys_get_temp_dir().'/'.uniqid('pdf_page_');

        // Convert all pages at 200 DPI for good OCR + processing quality
        $result = Process::timeout(60)->env($this->shellEnv())->run([
            $magick,
            '-density', '200',
            $pdfPath,
            '-quality', '92',
            $outputBase.'-%04d.jpg',
        ]);

        if ($result->failed()) {
            Log::warning('ImageProcessor: PDF to image conversion failed', [
                'path' => $pdfPath,
                'error' => $result->errorOutput(),
            ]);

            return [];
        }

        // Collect generated page files
        $pages = glob($outputBase.'-*.jpg');
        sort($pages);

        return $pages;
    }

    /**
     * Check if a file is an image (not PDF).
     */
    public function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    public function findMagick(): ?string
    {
        // Use command -v only — file_exists() fails under open_basedir (ISPConfig)
        // Try magick (v7 unified binary) first, then convert (v6)
        foreach (['magick', 'convert'] as $bin) {
            $check = Process::run("command -v $bin");
            if ($check->successful()) {
                return trim($check->output());
            }
        }

        return null;
    }

    public function findPython(): ?string
    {
        // Prefer the project venv (has opencv installed) — safe path under open_basedir
        $venvPython = base_path('scripts/python/.venv/bin/python3');
        if (@file_exists($venvPython)) {
            return $venvPython;
        }

        // Use command -v only — file_exists() fails under open_basedir (ISPConfig)
        $check = Process::run('command -v python3');

        return $check->successful() ? trim($check->output()) : null;
    }

    /**
     * Build an env array that ensures Homebrew/common bin dirs are in PATH.
     * PHP-FPM/Valet may have a minimal PATH that excludes Ghostscript, etc.
     */
    private function shellEnv(): array
    {
        $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
        $extraDirs = '/opt/homebrew/bin:/usr/local/bin';

        return [
            'PATH' => $extraDirs.':'.$systemPath,
        ];
    }
}
