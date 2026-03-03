<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-175: DTO for document activation upsert.
 */
final class UpsertDocumentActivationData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly string $documentCode,
        public readonly bool $enabled,
        public readonly bool $requiredOverride,
        public readonly int $order,
    ) {}
}
