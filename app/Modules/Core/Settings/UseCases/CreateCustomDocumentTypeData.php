<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-180: DTO for custom document type creation.
 */
final class CreateCustomDocumentTypeData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly string $label,
        public readonly string $scope,
        public readonly int $maxFileSizeMb,
        public readonly array $acceptedTypes,
        public readonly int $order,
        public readonly bool $required,
    ) {}
}
