<?php

namespace App\Modules\Core\Members\UseCases;

/**
 * ADR-174: Result of company document upload.
 */
final class UploadCompanyDocumentResult
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
