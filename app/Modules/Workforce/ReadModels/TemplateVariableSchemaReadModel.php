<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\Services\DocumentVariableResolver;

class TemplateVariableSchemaReadModel
{
    /**
     * Get all available variables for a given subject type.
     */
    public static function availableVariables(string $subjectType): array
    {
        if (! in_array($subjectType, DocumentTemplate::SUBJECT_TYPES, true)) {
            throw new \DomainException("Invalid subject type: {$subjectType}");
        }

        return DocumentVariableResolver::availableVariables($subjectType);
    }

    /**
     * Get all available variables for all subject types.
     */
    public static function allVariables(): array
    {
        $result = [];

        foreach (DocumentTemplate::SUBJECT_TYPES as $type) {
            $result[$type] = DocumentVariableResolver::availableVariables($type);
        }

        return $result;
    }

    /**
     * Validate that a set of variable keys are valid for a subject type.
     */
    public static function validate(string $subjectType, array $variableKeys): array
    {
        $available = DocumentVariableResolver::availableVariables($subjectType);
        $availableKeys = array_column($available, 'key');

        $invalid = array_diff($variableKeys, $availableKeys);

        return [
            'valid' => empty($invalid),
            'invalid_keys' => array_values($invalid),
            'available_count' => count($availableKeys),
        ];
    }
}
