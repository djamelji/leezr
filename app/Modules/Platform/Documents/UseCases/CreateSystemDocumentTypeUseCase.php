<?php

namespace App\Modules\Platform\Documents\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentType;

/**
 * ADR-182: Create a system document type from the platform admin.
 *
 * Pipeline: validate uniqueness → create type → audit → return.
 */
class CreateSystemDocumentTypeUseCase
{
    public function execute(CreateSystemDocumentTypeData $data): array
    {
        // 1. Check code uniqueness
        if (DocumentType::where('code', $data->code)->exists()) {
            abort(422, "A document type with code '{$data->code}' already exists.");
        }

        // 2. Create
        $type = DocumentType::create([
            'company_id' => null,
            'code' => $data->code,
            'scope' => $data->scope,
            'label' => $data->label,
            'validation_rules' => $data->validationRules,
            'is_system' => true,
            'default_order' => $data->defaultOrder,
        ]);

        // 3. Audit
        app(AuditLogger::class)->logPlatform(
            AuditAction::DOCUMENT_TYPE_CREATED, 'document_type', (string) $type->id,
            ['diffAfter' => $type->only('code', 'scope', 'label', 'validation_rules')],
        );

        return [
            'message' => 'Document type created.',
            'document_type' => $type,
        ];
    }
}
