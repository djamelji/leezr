<?php

namespace App\Modules\Core\Settings\UseCases;

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use Illuminate\Validation\ValidationException;

/**
 * ADR-175: Upsert document activation for a company.
 *
 * Pipeline: membership → type → system check → market filter → upsert → result.
 */
class UpsertDocumentActivationUseCase
{
    public function execute(UpsertDocumentActivationData $data): UpsertDocumentActivationResult
    {
        // 1. Verify actor membership (defense in depth)
        $data->company->memberships()
            ->where('user_id', $data->actor->id)
            ->firstOrFail();

        // 2. Load DocumentType by code
        $type = DocumentType::where('code', $data->documentCode)->firstOrFail();

        // 3. System types or custom types owned by this company
        if (! $type->is_system && $type->company_id !== $data->company->id) {
            throw ValidationException::withMessages([
                'document_code' => ['This document type does not belong to your company.'],
            ]);
        }

        // 4. Market filter: reject types not applicable to company market
        $rules = $type->validation_rules ?? [];
        $applicableMarkets = $rules['applicable_markets'] ?? null;
        $marketKey = $data->company->market_key;

        if ($applicableMarkets !== null && $marketKey && ! in_array($marketKey, $applicableMarkets)) {
            throw ValidationException::withMessages([
                'document_code' => ['This document type is not available for your market.'],
            ]);
        }

        // 5. Upsert activation
        $activation = DocumentTypeActivation::updateOrCreate(
            [
                'company_id' => $data->company->id,
                'document_type_id' => $type->id,
            ],
            [
                'enabled' => $data->enabled,
                'required_override' => $data->requiredOverride,
                'order' => $data->order,
            ],
        );

        // 6. Return result
        return new UpsertDocumentActivationResult(
            code: $type->code,
            enabled: $activation->enabled,
            requiredOverride: $activation->required_override,
            order: $activation->order,
        );
    }
}
