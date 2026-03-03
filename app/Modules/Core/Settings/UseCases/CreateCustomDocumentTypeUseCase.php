<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use Illuminate\Support\Str;

/**
 * ADR-180: Create a custom document type for a company.
 *
 * Pipeline: membership → generate code → collision guard → create type → auto-activate → result.
 */
class CreateCustomDocumentTypeUseCase
{
    public function execute(CreateCustomDocumentTypeData $data): CreateCustomDocumentTypeResult
    {
        // 1. Defense in depth: verify actor membership
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Generate unique code
        $baseCode = 'custom_'.$data->company->id.'_'.Str::slug($data->label, '_');
        $code = $baseCode;
        $suffix = 2;
        while (DocumentType::where('code', $code)->exists()) {
            $code = $baseCode.'_'.$suffix;
            $suffix++;
        }

        // 3. Create DocumentType
        $type = DocumentType::create([
            'company_id' => $data->company->id,
            'code' => $code,
            'scope' => $data->scope,
            'label' => $data->label,
            'is_system' => false,
            'default_order' => $data->order,
            'validation_rules' => [
                'max_file_size_mb' => $data->maxFileSizeMb,
                'accepted_types' => $data->acceptedTypes,
            ],
        ]);

        // 4. Auto-create activation (enabled by default)
        DocumentTypeActivation::create([
            'company_id' => $data->company->id,
            'document_type_id' => $type->id,
            'enabled' => true,
            'required_override' => $data->required,
            'order' => $data->order,
        ]);

        return new CreateCustomDocumentTypeResult(
            id: $type->id,
            code: $type->code,
            label: $type->label,
            scope: $type->scope,
        );
    }
}
