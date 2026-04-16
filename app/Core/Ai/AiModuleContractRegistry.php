<?php

namespace App\Core\Ai;

use App\Core\Ai\Contracts\AiModuleContract;

/**
 * ADR-436: Registry of AI-enabled modules.
 *
 * Replaces the hardcoded match($moduleKey) in AiPolicyResolver.
 * Each module registers its AiModuleContract implementation here.
 *
 * Pattern: same as AiProviderRegistry — static registry, no container magic.
 */
final class AiModuleContractRegistry
{
    /** @var array<string, AiModuleContract> */
    private static array $modules = [];

    /**
     * Register an AI module contract.
     */
    public static function register(AiModuleContract $module): void
    {
        self::$modules[$module->moduleKey()] = $module;
    }

    /**
     * Get a registered module contract by key.
     */
    public static function get(string $moduleKey): ?AiModuleContract
    {
        return self::$modules[$moduleKey] ?? null;
    }

    /**
     * Check if a module is registered.
     */
    public static function has(string $moduleKey): bool
    {
        return isset(self::$modules[$moduleKey]);
    }

    /**
     * Get all registered module keys.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::$modules);
    }

    /**
     * Get all registered modules.
     *
     * @return array<string, AiModuleContract>
     */
    public static function all(): array
    {
        return self::$modules;
    }

    /**
     * Clear registry (for testing).
     */
    public static function clear(): void
    {
        self::$modules = [];
    }
}
