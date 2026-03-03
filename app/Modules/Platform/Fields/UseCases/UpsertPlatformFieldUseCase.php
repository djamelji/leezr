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

        $before = $definition->only('label', 'validation_rules', 'options', 'default_order');
        $definition->update($validated);

        $this->audit->logPlatform(
            AuditAction::FIELD_UPDATED, 'field_definition', (string) $definition->id,
            ['diffBefore' => $before, 'diffAfter' => $definition->only('label', 'validation_rules', 'options', 'default_order')],
        );

        return $definition;
    }
}
