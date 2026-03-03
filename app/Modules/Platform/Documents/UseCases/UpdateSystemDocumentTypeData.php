<?php

namespace App\Modules\Platform\Documents\UseCases;

use Illuminate\Http\Request;

/**
 * ADR-182: DTO for system document type update.
 * Immutable: code, scope. Mutable: label, validation_rules, default_order.
 */
final class UpdateSystemDocumentTypeData
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $label,
        public readonly ?array $validationRules,
        public readonly ?int $defaultOrder,
    ) {}

    public static function from(Request $request, int $id): self
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'validation_rules' => ['sometimes', 'nullable', 'array'],
            'validation_rules.max_file_size_mb' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'validation_rules.accepted_types' => ['sometimes', 'array'],
            'validation_rules.accepted_types.*' => ['string'],
            'validation_rules.applicable_markets' => ['sometimes', 'nullable', 'array'],
            'validation_rules.applicable_markets.*' => ['string'],
            'validation_rules.required_by_jobdomains' => ['sometimes', 'nullable', 'array'],
            'validation_rules.required_by_jobdomains.*' => ['string'],
            'validation_rules.required_by_modules' => ['sometimes', 'nullable', 'array'],
            'validation_rules.required_by_modules.*' => ['string'],
            'validation_rules.tags' => ['sometimes', 'nullable', 'array'],
            'validation_rules.tags.*' => ['string'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return new self(
            id: $id,
            label: $validated['label'] ?? null,
            validationRules: array_key_exists('validation_rules', $validated) ? $validated['validation_rules'] : null,
            defaultOrder: $validated['default_order'] ?? null,
        );
    }
}
