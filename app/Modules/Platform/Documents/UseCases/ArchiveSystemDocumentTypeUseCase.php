<?php

namespace App\Modules\Platform\Documents\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;

/**
 * ADR-182: Archive a system document type.
 *
 * - Sets archived_at on the type
 * - Disables all company activations for this type (enabled → false)
 * - Does NOT modify jobdomain preset JSON (immutable historical data)
 * - Does NOT delete existing documents or requests
 * - Archived types are hidden from future company catalogs and preset selection
 */
class ArchiveSystemDocumentTypeUseCase
{
    public function execute(ArchiveSystemDocumentTypeData $data): void
    {
        $type = DocumentType::where('is_system', true)
            ->whereNull('company_id')
            ->whereNull('archived_at')
            ->findOrFail($data->id);

        // 1. Archive
        $type->update(['archived_at' => now()]);

        // 2. Disable all company activations
        $disabledCount = DocumentTypeActivation::where('document_type_id', $type->id)
            ->where('enabled', true)
            ->update(['enabled' => false]);

        // 3. Audit
        app(AuditLogger::class)->logPlatform(
            AuditAction::DOCUMENT_TYPE_ARCHIVED, 'document_type', (string) $type->id,
            [
                'code' => $type->code,
                'activations_disabled' => $disabledCount,
            ],
        );
    }
}
