<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-407: DTO for custom document type update.
 */
final class UpdateCustomDocumentTypeData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly string $code,
        public readonly string $label,
        public readonly int $maxFileSizeMb,
        public readonly array $acceptedTypes,
        public readonly bool $requiresExpiration,
    ) {}
}
