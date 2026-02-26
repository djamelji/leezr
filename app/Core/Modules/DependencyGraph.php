<?php

namespace App\Core\Modules;

/**
 * Graph introspection for module dependencies.
 *
 * Pure read-only queries on the requires graph from module manifests.
 * No cycle detection (handled by DependencyGraphValidator).
 * No activation state — manifests only.
 */
class DependencyGraph
{
    /**
     * Direct requires for a module.
     *
     * @return string[] Module keys this module directly requires
     */
    public static function requires(string $moduleKey): array
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        return $manifest?->requires ?? [];
    }

    /**
     * Transitive closure of requires (DFS).
     * Returns all modules required directly or transitively, excluding self.
     *
     * @return string[] Module keys sorted alphabetically for determinism
     */
    public static function requiresClosure(string $moduleKey): array
    {
        $visited = [];

        static::dfs($moduleKey, $visited);

        unset($visited[$moduleKey]);

        $keys = array_keys($visited);
        sort($keys);

        return $keys;
    }

    /**
     * Reverse lookup: modules that directly require the given module.
     *
     * @return string[] Module keys sorted alphabetically for determinism
     */
    public static function requiredBy(string $moduleKey): array
    {
        $dependents = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if (in_array($moduleKey, $manifest->requires, true)) {
                $dependents[] = $key;
            }
        }

        sort($dependents);

        return $dependents;
    }

    /**
     * Full adjacency list of the dependency graph.
     * Only includes modules that have at least one require.
     *
     * @return array<string, string[]> module_key => [required_module_keys]
     */
    public static function buildFullGraph(): array
    {
        $graph = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if (!empty($manifest->requires)) {
                $graph[$key] = $manifest->requires;
            }
        }

        ksort($graph);

        return $graph;
    }

    // ─── Internal ────────────────────────────────────────────

    private static function dfs(string $key, array &$visited): void
    {
        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (!$manifest || empty($manifest->requires)) {
            return;
        }

        foreach ($manifest->requires as $reqKey) {
            static::dfs($reqKey, $visited);
        }
    }
}
