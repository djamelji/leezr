<?php

namespace App\Core\Automation;

/**
 * ADR-437: Declarative registry of workflow triggers.
 *
 * Each module declares which domain events can be used as workflow triggers.
 * Maps topic → trigger metadata (label, available conditions, available actions).
 *
 * Pattern: same as AiModuleContractRegistry, PaymentRegistry — static, no container.
 */
final class WorkflowTriggerRegistry
{
    /** @var array<string, array{label: string, conditions: array, actions: array}> */
    private static array $triggers = [];

    /**
     * Register a trigger topic.
     *
     * @param  string  $topic  The domain event topic (e.g., 'document.updated')
     * @param  string  $label  Human-readable label
     * @param  array  $conditions  Available condition fields [{field, type, label}]
     * @param  array  $actions  Available action types [{type, label, config_schema}]
     */
    public static function register(string $topic, string $label, array $conditions = [], array $actions = []): void
    {
        self::$triggers[$topic] = [
            'label' => $label,
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }

    public static function get(string $topic): ?array
    {
        return self::$triggers[$topic] ?? null;
    }

    public static function has(string $topic): bool
    {
        return isset(self::$triggers[$topic]);
    }

    /**
     * @return array<string, array>
     */
    public static function all(): array
    {
        return self::$triggers;
    }

    /**
     * @return string[]
     */
    public static function topics(): array
    {
        return array_keys(self::$triggers);
    }

    public static function clear(): void
    {
        self::$triggers = [];
    }
}
