<?php

namespace App\Modules\Platform\Markets\UseCases;

use App\Core\Markets\LegalStatus;
use App\Core\Markets\Market;
use Illuminate\Validation\ValidationException;

class UpsertLegalStatusUseCase
{
    public function execute(UpsertLegalStatusData $data): LegalStatus
    {
        // Enforce VAT rules: if not VAT-applicable, vat_rate must be null
        $vatRate = $data->isVatApplicable ? ($data->vatRate ?? 0) : null;

        if ($data->id) {
            // Update
            $status = LegalStatus::findOrFail($data->id);
            $marketKey = $status->market_key;

            // Uniqueness check within market (exclude self)
            if (LegalStatus::where('market_key', $marketKey)
                ->where('key', $data->key)
                ->where('id', '!=', $data->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'key' => 'Legal status key already exists for this market.',
                ]);
            }

            // Default management
            if ($data->isDefault) {
                LegalStatus::where('market_key', $marketKey)
                    ->where('is_default', true)
                    ->where('id', '!=', $data->id)
                    ->update(['is_default' => false]);
            }

            $status->update([
                'key' => $data->key,
                'name' => $data->name,
                'description' => $data->description,
                'is_vat_applicable' => $data->isVatApplicable,
                'vat_rate' => $vatRate,
                'is_default' => $data->isDefault,
                'sort_order' => $data->sortOrder,
            ]);
        } else {
            // Create
            Market::where('key', $data->marketKey)->firstOrFail();

            // Uniqueness check within market
            if (LegalStatus::where('market_key', $data->marketKey)->where('key', $data->key)->exists()) {
                throw ValidationException::withMessages([
                    'key' => 'Legal status key already exists for this market.',
                ]);
            }

            // Default management
            if ($data->isDefault) {
                LegalStatus::where('market_key', $data->marketKey)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $status = LegalStatus::create([
                'market_key' => $data->marketKey,
                'key' => $data->key,
                'name' => $data->name,
                'description' => $data->description,
                'is_vat_applicable' => $data->isVatApplicable,
                'vat_rate' => $vatRate,
                'is_default' => $data->isDefault,
                'sort_order' => $data->sortOrder,
            ]);
        }

        return $status;
    }
}
