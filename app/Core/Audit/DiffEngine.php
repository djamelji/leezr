<?php

namespace App\Core\Audit;

/**
 * ADR-130: Computes diffs between before/after states for audit logs.
 *
 * Returns a structured diff with added, removed, and changed keys.
 * Filters out sensitive fields automatically.
 */
final class DiffEngine
{
    /**
     * Sensitive field names that should never appear in audit diffs.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_hash',
        'secret',
        'api_key',
        'api_secret',
        'token',
        'credentials',
        'private_key',
        'remember_token',
    ];

    /**
     * Compute the diff between two associative arrays.
     *
     * @return array{added: array, removed: array, changed: array}
     */
    public static function diff(array $before, array $after): array
    {
        $before = self::filterSensitive($before);
        $after = self::filterSensitive($after);

        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($allKeys as $key) {
            $inBefore = array_key_exists($key, $before);
            $inAfter = array_key_exists($key, $after);

            if (!$inBefore && $inAfter) {
                $added[$key] = $after[$key];
            } elseif ($inBefore && !$inAfter) {
                $removed[$key] = $before[$key];
            } elseif ($before[$key] !== $after[$key]) {
                $changed[$key] = [
                    'from' => $before[$key],
                    'to' => $after[$key],
                ];
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /**
     * Check if a diff is empty (no changes).
     */
    public static function isEmpty(array $diff): bool
    {
        return empty($diff['added']) && empty($diff['removed']) && empty($diff['changed']);
    }

    /**
     * Remove sensitive fields from an array (recursive for nested).
     */
    private static function filterSensitive(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::SENSITIVE_FIELDS, true)) {
                $filtered[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $filtered[$key] = self::filterSensitive($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
