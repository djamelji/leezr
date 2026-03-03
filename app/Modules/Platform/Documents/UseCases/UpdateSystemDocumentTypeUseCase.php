<?php

namespace App\Modules\Platform\Documents\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentType;

/**
 * ADR-182: Update a system document type (mutable fields only).
 *
 * Immutable: code, scope (risk of breaking existing documents).
 * Mutable: label, validation_rules, default_order.
 */
class UpdateSystemDocumentTypeUseCase
{
    public function execute(UpdateSystemDocumentTypeData $data): array
    {
        $type = DocumentType::where('is_system', true)
            ->whereNull('company_id')
            ->findOrFail($data->id);

        $before = $type->only('label', 'validation_rules', 'default_order');

        $updates = [];
        if ($data->label !== null) {
            $updates['label'] = $data->label;
        }
        if ($data->validationRules !== null) {
            $updates['validation_rules'] = $data->validationRules;
        }
        if ($data->defaultOrder !== null) {
            $updates['default_order'] = $data->defaultOrder;
        }

        if (! empty($updates)) {
            $type->update($updates);
        }

        app(AuditLogger::class)->logPlatform(
            AuditAction::DOCUMENT_TYPE_UPDATED, 'document_type', (string) $type->id,
            [
                'diffBefore' => $before,
                'diffAfter' => $type->only('label', 'validation_rules', 'default_order'),
            ],
        );

        return [
            'message' => 'Document type updated.',
            'document_type' => $type->fresh(),
        ];
    }
}
