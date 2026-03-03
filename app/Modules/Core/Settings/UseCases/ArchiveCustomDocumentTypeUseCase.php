<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use Illuminate\Validation\ValidationException;

/**
 * ADR-180: Archive a custom document type.
 *
 * Pipeline: membership → load type → verify ownership → archive → disable activation.
 */
class ArchiveCustomDocumentTypeUseCase
{
    public function execute(ArchiveCustomDocumentTypeData $data): void
    {
        // 1. Defense in depth: verify actor membership
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Load type by code
        $type = DocumentType::where('code', $data->code)->firstOrFail();

        // 3. Verify ownership and custom
        if ($type->is_system || $type->company_id !== $data->company->id) {
            throw ValidationException::withMessages([
                'code' => ['Only custom document types owned by your company can be archived.'],
            ]);
        }

        // 4. Archive
        $type->update(['archived_at' => now()]);

        // 5. Disable activation
        DocumentTypeActivation::where('company_id', $data->company->id)
            ->where('document_type_id', $type->id)
            ->update(['enabled' => false]);
    }
}
