<?php

namespace App\Modules\Core\Members\UseCases;

/**
 * ADR-173: Result of self-document upload.
 */
final class UploadOwnDocumentResult
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $fileName,
        public readonly int $fileSizeBytes,
        public readonly string $uploadedAt,
        public readonly bool $replaced,
    ) {}
}
