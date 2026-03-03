<?php

namespace App\Modules\Platform\Documents\UseCases;

/**
 * ADR-182: DTO for system document type archival.
 */
final class ArchiveSystemDocumentTypeData
{
    public function __construct(
        public readonly int $id,
    ) {}
}
