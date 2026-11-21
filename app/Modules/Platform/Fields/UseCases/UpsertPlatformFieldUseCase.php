<?php

namespace App\Modules\Platform\Fields\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Fields\FieldDefinition;
use Illuminate\Validation\ValidationException;

class UpsertPlatformFieldUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(?int $id, array $validated): FieldDefinition
    {
        if ($id === null) {
            return $this->create($validated);
        }

        return $this->update($id, $validated);
    }

    private function create(array $validated): FieldDefinition
    {
        $exists = FieldDefinition::whereNull('company_id')
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['The code has already been taken.'],
            ]);
        }

        $definition = FieldDefinition::create(array_merge($validated, [
            'company_id' => null,
            'is_system' => false,
            'created_by_platform' => true,
        ]));

        $this->audit->logPlatform(
            AuditAction::FIELD_CREATED, 'field_definition', (string) $definition->id,
            ['diffAfter' => $definition->only('code', 'scope', 'label', 'type')],
        );

        return $definition;
    }

    private function update(int $id, array $validated): FieldDefinition
    {
        $definition = FieldDefinition::whereNull('company_id')->findOrFail($id);

        $before = $definition->only('label', 'translations', 'validation_rules', 'options', 'default_order');
        if ($definition->scope === FieldDefinition::SCOPE_COMPANY && array_key_exists('validation_rules', $validated)) {
            $this->assertCreationRules($definition, (array) ($validated['validation_rules'] ?? []), $validated['options'] ?? $definition->options);
        }
        $definition->update($validated);

        $this->audit->logPlatform(
            AuditAction::FIELD_UPDATED, 'field_definition', (string) $definition->id,
            ['diffBefore' => $before, 'diffAfter' => $definition->only('label', 'translations', 'validation_rules', 'options', 'default_order')],
        );

        return $definition;
    }

    /**
     * ADR-835 — creation rules of a company field in the global catalog : legal markets within the field's markets,
     * a legally imposed field is required, a default value respects the technical limits.
     */
    private function assertCreationRules(FieldDefinition $definition, array $rules, mixed $options): void
    {
        $errors = [];
        $markets = $rules['applicable_markets'] ?? null;
        $legal = (array) ($rules['legal_required_markets'] ?? []);
        if ($legal !== [] && $markets !== null && array_diff($legal, (array) $markets) !== []) {
            $errors['validation_rules.legal_required_markets'] = [__('jobdomainCompanyFields.errors.marketsOutsideCatalog', ['markets' => implode(', ', array_diff($legal, (array) $markets))])];
        }
        if ($legal !== [] && empty($rules['required'])) {
            $errors['validation_rules.required'] = [__('jobdomainCompanyFields.errors.legal', ['markets' => implode(', ', $legal)])];
        }
        $default = trim((string) ($rules['default'] ?? ''));
        if ($default !== '' && \App\Core\Fields\CompanyCreationFields::valueProblem([
            'type' => $definition->type, 'options' => $options, 'max' => $rules['max'] ?? null, 'pattern' => $rules['pattern'] ?? null,
        ], $default) !== null) {
            $errors['validation_rules.default'] = [__('jobdomainCompanyFields.errors.default')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
