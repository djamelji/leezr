<?php

namespace App\Modules\Core\Members\UseCases;

/**
 * ADR-173: Result of self-document download.
 */
final class DownloadOwnDocumentResult
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $fileName,
        public readonly string $disk,
    ) {}
}
