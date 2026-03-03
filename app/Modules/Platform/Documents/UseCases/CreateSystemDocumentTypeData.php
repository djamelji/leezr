<?php

namespace App\Modules\Platform\Documents\UseCases;

use Illuminate\Http\Request;

/**
 * ADR-182: DTO for system document type creation.
 */
final class CreateSystemDocumentTypeData
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $scope,
        public readonly array $validationRules,
        public readonly int $defaultOrder = 0,
    ) {}

    public static function from(Request $request): self
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'string', 'in:company,company_user'],
            'validation_rules' => ['sometimes', 'array'],
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
            code: $validated['code'],
            label: $validated['label'],
            scope: $validated['scope'],
            validationRules: $validated['validation_rules'] ?? [
                'max_file_size_mb' => 10,
                'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
            ],
            defaultOrder: $validated['default_order'] ?? 0,
        );
    }
}
