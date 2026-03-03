<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-180: DTO for member document deletion with workflow reset.
 */
final class DeleteMemberDocumentData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly int $membershipId,
        public readonly string $documentCode,
    ) {}
}
