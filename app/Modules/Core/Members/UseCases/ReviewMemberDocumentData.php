<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-176: DTO for member document review.
 */
final class ReviewMemberDocumentData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly int $membershipId,
        public readonly string $documentCode,
        public readonly string $status,
        public readonly ?string $reviewNote,
    ) {}
}
