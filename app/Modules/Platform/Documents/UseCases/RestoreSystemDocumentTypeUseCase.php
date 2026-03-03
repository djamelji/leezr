<?php

namespace App\Modules\Platform\Documents\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentType;

/**
 * ADR-182: Restore an archived system document type.
 *
 * - Clears archived_at
 * - Does NOT re-enable company activations (companies must re-activate manually)
 * - Type becomes available for future company catalogs and preset selection
 */
class RestoreSystemDocumentTypeUseCase
{
    public function execute(RestoreSystemDocumentTypeData $data): void
    {
        $type = DocumentType::where('is_system', true)
            ->whereNull('company_id')
            ->whereNotNull('archived_at')
            ->findOrFail($data->id);

        $type->update(['archived_at' => null]);

        app(AuditLogger::class)->logPlatform(
            AuditAction::DOCUMENT_TYPE_RESTORED, 'document_type', (string) $type->id,
            ['code' => $type->code],
        );
    }
}
