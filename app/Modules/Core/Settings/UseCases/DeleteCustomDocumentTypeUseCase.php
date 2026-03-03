<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use Illuminate\Validation\ValidationException;

/**
 * ADR-180: Delete a custom document type (only if zero references).
 *
 * Pipeline: membership → load type → verify ownership → check references → delete.
 */
class DeleteCustomDocumentTypeUseCase
{
    public function execute(DeleteCustomDocumentTypeData $data): void
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
                'code' => ['Only custom document types owned by your company can be deleted.'],
            ]);
        }

        // 4. Check references
        $memberDocCount = MemberDocument::where('document_type_id', $type->id)->count();
        $companyDocCount = CompanyDocument::where('document_type_id', $type->id)->count();
        $requestCount = DocumentRequest::where('document_type_id', $type->id)->count();

        if ($memberDocCount + $companyDocCount + $requestCount > 0) {
            throw ValidationException::withMessages([
                'code' => ['Cannot delete: linked documents exist. Use archive instead.'],
            ]);
        }

        // 5. Delete activation + type
        DocumentTypeActivation::where('company_id', $data->company->id)
            ->where('document_type_id', $type->id)
            ->delete();

        $type->delete();
    }
}
