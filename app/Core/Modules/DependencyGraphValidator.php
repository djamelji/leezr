<?php

namespace App\Core\Modules;

use RuntimeException;

/**
 * Validates the module dependency graph for structural integrity.
 *
 * - Detects cycles in the requires graph (DFS with coloring)
 * - Validates pricing invariants (required modules cannot be addon-priced)
 * - Run at boot/seed time to catch manifest errors early
 */
class DependencyGraphValidator
{
    private const WHITE = 0; // unvisited
    private const GRAY = 1;  // in current DFS path
    private const BLACK = 2; // fully processed

    /**
     * Validate the entire dependency graph.
     * Throws RuntimeException on first error found.
     *
     * @throws RuntimeException
     */
    public static function validate(): void
    {
        static::detectCycles();
        static::validatePricingInvariants();
    }

    /**
     * Detect cycles in the requires graph using DFS with coloring.
     *
     * @throws RuntimeException with cycle path description
     */
    public static function detectCycles(): void
    {
        $definitions = ModuleRegistry::definitions();
        $colors = [];

        foreach ($definitions as $key => $manifest) {
            $colors[$key] = self::WHITE;
        }

        foreach ($definitions as $key => $manifest) {
            if ($colors[$key] === self::WHITE) {
                static::dfs($key, $colors, $definitions, []);
            }
        }
    }

    /**
     * Validate pricing invariant: a module that is required by another
     * cannot have pricing_mode = 'addon'.
     *
     * Rationale: if module B requires module A, then A is auto-activated
     * when B is activated. An addon-priced A would mean the company pays
     * for A without explicitly choosing it. This is a billing hazard.
     *
     * @throws RuntimeException
     */
    public static function validatePricingInvariants(): void
    {
        $definitions = ModuleRegistry::definitions();

        // Collect all module keys that are required by at least one other module
        $requiredKeys = [];

        foreach ($definitions as $key => $manifest) {
            foreach ($manifest->requires as $reqKey) {
                $requiredKeys[$reqKey][] = $key;
            }
        }

        // Check pricing for each required module
        foreach ($requiredKeys as $requiredKey => $dependentKeys) {
            $platformModule = PlatformModule::where('key', $requiredKey)->first();

            if (!$platformModule) {
                continue; // Module not yet synced, skip
            }

            if ($platformModule->pricing_mode === 'addon') {
                $dependentList = implode(', ', $dependentKeys);

                throw new RuntimeException(
                    "Pricing invariant violation: module '{$requiredKey}' is required by [{$dependentList}] "
                    . "but has pricing_mode='addon'. Required modules must use 'included' or 'internal' pricing."
                );
            }
        }
    }

    /**
     * Get the full dependency graph as adjacency list.
     * Useful for debugging and visualization.
     *
     * @return array<string, string[]> module_key => [required_module_keys]
     */
    public static function graph(): array
    {
        $graph = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if (!empty($manifest->requires)) {
                $graph[$key] = $manifest->requires;
            }
        }

        return $graph;
    }

    // ─── Internal ────────────────────────────────────────────

    /**
     * @param string[] $path Current DFS path for error reporting
     * @throws RuntimeException
     */
    private static function dfs(
        string $key,
        array &$colors,
        array $definitions,
        array $path,
    ): void {
        $colors[$key] = self::GRAY;
        $path[] = $key;

        $manifest = $definitions[$key] ?? null;

        if (!$manifest) {
            $colors[$key] = self::BLACK;

            return;
        }

        foreach ($manifest->requires as $reqKey) {
            if (!isset($colors[$reqKey])) {
                // Required module not in registry — skip (caught by other validators)
                continue;
            }

            if ($colors[$reqKey] === self::GRAY) {
                // Cycle detected — build path from reqKey to current position
                $cycleStart = array_search($reqKey, $path, true);
                $cyclePath = array_slice($path, $cycleStart);
                $cyclePath[] = $reqKey; // Close the cycle
                $cycleStr = implode(' → ', $cyclePath);

                throw new RuntimeException(
                    "Cycle detected in module dependency graph: {$cycleStr}"
                );
            }

            if ($colors[$reqKey] === self::WHITE) {
                static::dfs($reqKey, $colors, $definitions, $path);
            }
        }

        $colors[$key] = self::BLACK;
    }
}
