<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * ADR-173: DTO for self-document upload.
 */
final class UploadOwnDocumentData
{
    public function __construct(
        public readonly User $user,
        public readonly Company $company,
        public readonly string $documentCode,
        public readonly UploadedFile $file,
        public readonly ?string $expiresAt = null,
    ) {}
}
