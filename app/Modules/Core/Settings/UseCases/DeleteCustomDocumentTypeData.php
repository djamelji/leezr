<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-180: DTO for custom document type deletion.
 */
final class DeleteCustomDocumentTypeData
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly string $code,
    ) {}
}
