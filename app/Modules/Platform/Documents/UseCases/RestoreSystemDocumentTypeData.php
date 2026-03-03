<?php

namespace App\Modules\Platform\Documents\UseCases;

/**
 * ADR-182: DTO for system document type restoration.
 */
final class RestoreSystemDocumentTypeData
{
    public function __construct(
        public readonly int $id,
    ) {}
}
