<?php

namespace App\Core\Documents\UseCases;

/**
 * ADR-174: Result of company document download.
 */
final class DownloadCompanyDocumentResult
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $fileName,
        public readonly string $disk,
    ) {}
}
