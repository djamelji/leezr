<?php

namespace App\Core\Modules;

use RuntimeException;

/**
 * Validates the module dependency graph for structural integrity.
 *
 * - Detects cycles in the requires graph (DFS with coloring)
 * - Run at boot/seed time to catch manifest errors early
 *
 * ADR-206: Pricing invariants moved to ModulePricingPolicy (addon_pricing-based).
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
