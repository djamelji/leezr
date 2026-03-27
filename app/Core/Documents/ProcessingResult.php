<?php

namespace App\Core\Documents;

class ProcessingResult
{
    public function __construct(
        public readonly string $pdfPath,
        public readonly int $fileSize,
        public readonly string $fileName,
        public readonly ?string $ocrText,
        public readonly int $pageCount,
        public readonly string $mimeType = 'application/pdf',
        public readonly bool $passthrough = false,
    ) {}
}
