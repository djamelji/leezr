<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Documents\DocumentType;
use Illuminate\Validation\ValidationException;

/**
 * ADR-407: Update a custom document type owned by the company.
 *
 * Pipeline: membership → load type → ownership guard → system guard → update.
 * Scope and code are immutable.
 */
class UpdateCustomDocumentTypeUseCase
{
    public function execute(UpdateCustomDocumentTypeData $data): DocumentType
    {
        // 1. Defense in depth: verify actor membership
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Load type by code
        $type = DocumentType::where('code', $data->code)->firstOrFail();

        // 3. Guard: must be custom (not system)
        if ($type->is_system) {
            throw ValidationException::withMessages([
                'code' => ['System document types cannot be edited.'],
            ]);
        }

        // 4. Guard: must belong to this company
        if ((int) $type->company_id !== (int) $data->company->id) {
            throw ValidationException::withMessages([
                'code' => ['This document type does not belong to your company.'],
            ]);
        }

        // 5. Update mutable fields
        $type->update([
            'label' => $data->label,
            'requires_expiration' => $data->requiresExpiration,
            'validation_rules' => [
                'max_file_size_mb' => $data->maxFileSizeMb,
                'accepted_types' => $data->acceptedTypes,
            ],
        ]);

        return $type->fresh();
    }
}
