<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * ADR-174: DTO for company document upload.
 */
final class UploadCompanyDocumentData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly string $documentCode,
        public readonly UploadedFile $file,
    ) {}
}
