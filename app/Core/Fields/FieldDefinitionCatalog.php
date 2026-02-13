<?php

namespace App\Core\Fields;

/**
 * Single source of truth for system field definitions.
 * Pattern: same as PermissionCatalog, ModuleRegistry.
 *
 * sync() is idempotent and safe for any environment.
 * System fields cannot be deleted. scope/type are never modified on existing fields.
 */
class FieldDefinitionCatalog
{
    public static function all(): array
    {
        return [
            // Company scope
            [
                'code' => 'siret',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'SIRET',
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['required' => true, 'max' => 14],
                'default_order' => 10,
            ],
            [
                'code' => 'vat_number',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'VAT Number',
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20],
                'default_order' => 20,
            ],
            [
                'code' => 'legal_form',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Legal Form',
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 50],
                'default_order' => 30,
            ],

            // Company User scope
            [
                'code' => 'phone',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Phone',
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20],
                'default_order' => 10,
            ],
            [
                'code' => 'job_title',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Job Title',
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100],
                'default_order' => 20,
            ],

            // Platform User scope
            [
                'code' => 'internal_note',
                'scope' => FieldDefinition::SCOPE_PLATFORM_USER,
                'label' => 'Internal Note',
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 500],
                'default_order' => 10,
            ],
        ];
    }

    /**
     * Sync definitions to DB (idempotent).
     *
     * Only updates safe fields (label, validation_rules, options, default_order).
     * Never modifies scope or type of existing fields.
     */
    public static function sync(): void
    {
        foreach (static::all() as $field) {
            FieldDefinition::updateOrCreate(
                ['company_id' => null, 'code' => $field['code']],
                [
                    'scope' => $field['scope'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'validation_rules' => $field['validation_rules'] ?? null,
                    'options' => $field['options'] ?? null,
                    'is_system' => true,
                    'created_by_platform' => true,
                    'default_order' => $field['default_order'] ?? 0,
                ],
            );
        }
    }
}
