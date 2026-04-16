<?php

namespace App\Core\Automation;

/**
 * ADR-437: Evaluates workflow conditions against a trigger payload.
 *
 * Conditions are JSON arrays of {field, operator, value}.
 * All conditions must be met (AND logic).
 *
 * Supported operators: =, !=, >, <, >=, <=, in, not_in, contains, starts_with
 */
final class ConditionEvaluator
{
    /**
     * Evaluate all conditions against the payload.
     *
     * @param  array  $conditions  [{field, operator, value}]
     * @param  array  $payload  Trigger event payload
     */
    public static function evaluate(array $conditions, array $payload): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! self::evaluateOne($condition, $payload)) {
                return false;
            }
        }

        return true;
    }

    private static function evaluateOne(array $condition, array $payload): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? '=';
        $expected = $condition['value'] ?? null;

        $actual = data_get($payload, $field);

        return match ($operator) {
            '=', '==' => $actual == $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected),
            'not_in' => is_array($expected) && ! in_array($actual, $expected),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'starts_with' => is_string($actual) && is_string($expected) && str_starts_with($actual, $expected),
            default => false,
        };
    }
}
