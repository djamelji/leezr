<?php

namespace App\Core\Navigation;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use RuntimeException;

/**
 * Single engine for navigation computation — both admin and company scopes.
 *
 * Pipeline (identical for both scopes):
 *  1. Collect manifests → filter active + visible
 *  2. Sort by sortOrder → flatMap navItems → NavItem VOs
 *  3. Filter: item-level plan (company only)
 *  4. Filter: item-level jobdomain (company only)
 *  5. Filter: permissions (backend = source of truth)
 *  6. Filter: excludePermission (hide if user HAS the permission)
 *  7. Filter: surface/roleLevel (company only)
 *  8. Build parent→child tree + cycle detection
 *  9. Group by resolved group key
 * 10. Prune: empty groups + non-clickable parents without children
 * 11. Sort within groups + validate unique keys
 *
 * Returns: [{key, titleKey, items: [{key, title, to, icon, permission, children}]}]
 */
class NavBuilder
{
    /**
     * Explicit group ordering for admin scope.
     * Groups are sorted by their position in this array.
     * Groups not listed here appear at the end.
     */
    private const ADMIN_GROUP_ORDER = [
        'cockpit',
        'clients',
        'finance',
        'product',
        'operations',
        'administration',
    ];

    /**
     * Build grouped navigation for admin (platform) scope.
     *
     * @param  array|null  $permissions  Permission keys. null = bypass (super_admin).
     * @return array  [{key, titleKey, items: [...]}]
     */
    public static function forAdmin(?array $permissions = null): array
    {
        return static::build('admin', null, $permissions, null);
    }

    /**
     * Build grouped navigation for company scope.
     *
     * @param  array|null  $permissions  Permission keys. null = bypass (owner only).
     * @param  string      $roleLevel    'management' | 'operational'
     * @return array  [{key, titleKey, items: [...]}]
     */
    public static function forCompany(Company $company, ?array $permissions = null, string $roleLevel = 'operational'): array
    {
        return static::build('company', $company, $permissions, $roleLevel);
    }

    /**
     * Flat items for legacy cookie hydration (used by PlatformAuthController only).
     * Returns the same data as the old platformModuleNavItems() method.
     */
    public static function flatForAdmin(): array
    {
        $groups = static::forAdmin();
        $items = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $items[] = [
                    'key' => $item['key'],
                    'title' => $item['title'],
                    'to' => $item['to'],
                    'icon' => $item['icon'],
                    'permission' => $item['permission'] ?? null,
                ];
            }
        }

        return $items;
    }

    /**
     * Core pipeline — same for both scopes.
     */
    private static function build(string $scope, ?Company $company, ?array $permissions, ?string $roleLevel): array
    {
        // 1. Collect manifests → filter active + visible
        $manifests = collect(ModuleRegistry::forScope($scope === 'admin' ? 'admin' : 'company'))
            ->filter(fn (ModuleManifest $m) => $m->visibility === 'visible')
            ->filter(fn (ModuleManifest $m) => ModuleGate::isActiveForScope($m->key, $company))
            ->sortBy('sortOrder');

        // 2. FlatMap navItems → NavItem VOs
        $items = $manifests->flatMap(function (ModuleManifest $m) {
            return collect($m->capabilities->navItems)
                ->map(fn (array $data) => NavItem::fromManifestArray($data, $m->sortOrder));
        })->values()->all();

        // Validate unique keys early
        static::validateUniqueKeys($items);

        // 3. Filter: item-level plan (company only)
        if ($company) {
            $planKey = CompanyEntitlements::planKey($company);
            $items = array_filter($items, function (NavItem $item) use ($planKey) {
                if ($item->plans === null) {
                    return true;
                }

                return in_array($planKey, $item->plans, true);
            });
        }

        // 4. Filter: item-level jobdomain (company only)
        // ADR-167a: jobdomain_key is always present — no null check needed
        if ($company) {
            $jobdomainKey = $company->jobdomain_key;
            $items = array_filter($items, function (NavItem $item) use ($jobdomainKey) {
                if ($item->jobdomains === null) {
                    return true;
                }

                return in_array($jobdomainKey, $item->jobdomains, true);
            });
        }

        // 5. Filter: permissions (backend = source of truth)
        //    null = owner/super_admin bypass (sees everything)
        //    [] = no permissions (sees only items without permission requirement)
        if ($permissions !== null) {
            $permissionSet = array_flip($permissions);
            $items = array_filter($items, function (NavItem $item) use ($permissionSet) {
                if ($item->permission === null) {
                    return true;
                }

                return isset($permissionSet[$item->permission]);
            });
        }

        // 6. Filter: excludePermission (hides item if user HAS the specified permission)
        //    Applies even to bypass users (permissions=null → has all permissions → excluded)
        $items = array_filter($items, function (NavItem $item) use ($permissions) {
            if ($item->excludePermission === null) {
                return true;
            }

            // Bypass users have all permissions → exclude
            if ($permissions === null) {
                return false;
            }

            $permSet = array_flip($permissions);

            return ! isset($permSet[$item->excludePermission]);
        });

        // 7. Filter: surface/roleLevel (company only)
        if ($roleLevel !== null) {
            $items = array_filter($items, function (NavItem $item) use ($roleLevel) {
                // Structure items hidden from operational
                if ($item->surface === 'structure' && $roleLevel !== 'management') {
                    return false;
                }

                // Operational-only items hidden from management
                if ($item->operationalOnly && $roleLevel === 'management') {
                    return false;
                }

                return true;
            });
        }

        $items = array_values($items);

        // 8. Build parent→child tree
        $tree = static::buildTree($items);

        // 9. Group by resolved group key
        $grouped = [];

        foreach ($tree as $node) {
            $groupKey = static::resolveGroup($node['_item'], $scope);

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'key' => $groupKey,
                    'titleKey' => static::groupTitleKey($groupKey),
                    'items' => [],
                ];
            }

            $grouped[$groupKey]['items'][] = $node;
        }

        // 10. Prune: empty groups + non-clickable parents without children
        $grouped = static::pruneGroups($grouped);

        // 10b. Sort groups by explicit order (admin scope has ADMIN_GROUP_ORDER)
        if ($scope === 'admin') {
            $grouped = static::sortGroupsByOrder($grouped, self::ADMIN_GROUP_ORDER);
        }

        // 11. Sort within groups + clean output
        $result = [];

        foreach ($grouped as $group) {
            $sortedItems = collect($group['items'])
                ->sortBy('_sort')
                ->map(fn (array $node) => static::cleanNode($node))
                ->values()
                ->all();

            if (!empty($sortedItems)) {
                $result[] = [
                    'key' => $group['key'],
                    'titleKey' => $group['titleKey'],
                    'items' => $sortedItems,
                ];
            }
        }

        return $result;
    }

    // ─── Tree ─────────────────────────────────────────────────

    /**
     * Build parent→child tree. Cycle detection via chain walk.
     * Orphan parents (parent filtered out): child promoted to root.
     */
    private static function buildTree(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $map[$item->key] = [
                '_item' => $item,
                '_sort' => $item->sort,
                'key' => $item->key,
                'title' => $item->title,
                'to' => $item->to,
                'icon' => $item->icon,
                'permission' => $item->permission,
                'children' => [],
            ];
        }

        // Detect cycles before building tree
        $parentMap = [];

        foreach ($items as $item) {
            if ($item->parent !== null) {
                $parentMap[$item->key] = $item->parent;
            }
        }

        foreach ($parentMap as $key => $parent) {
            static::detectCycle($key, $parentMap);
        }

        // Assign children
        $roots = [];

        foreach ($items as $item) {
            if ($item->parent !== null && isset($map[$item->parent])) {
                $map[$item->parent]['children'][] = &$map[$item->key];
            } else {
                $roots[] = &$map[$item->key];
            }
        }

        return $roots;
    }

    /**
     * Detect cycles in parent chain. Walk from startKey up the chain.
     * Throws RuntimeException on loop.
     */
    private static function detectCycle(string $startKey, array $parentMap): void
    {
        $visited = [];
        $current = $startKey;

        while (isset($parentMap[$current])) {
            if (isset($visited[$current])) {
                throw new RuntimeException(
                    "Navigation cycle detected: {$startKey} → " . implode(' → ', array_keys($visited)) . " → {$current}"
                );
            }

            $visited[$current] = true;
            $current = $parentMap[$current];
        }
    }

    // ─── Grouping ─────────────────────────────────────────────

    /**
     * Resolve group key for an item. Explicit group takes priority.
     * Otherwise derived from scope/surface.
     */
    private static function resolveGroup(NavItem $item, string $scope): string
    {
        if ($item->group !== null) {
            return $item->group;
        }

        if ($scope === 'admin') {
            return 'management';
        }

        // Company scope: derive from surface
        if ($item->surface === 'operations') {
            return 'operations';
        }

        return 'company';
    }

    /**
     * Group title keys — i18n keys, NOT hardcoded strings.
     */
    private static function groupTitleKey(string $groupKey): string
    {
        if ($groupKey === '' || $groupKey === 'root') {
            return '';
        }

        return "nav.groups.{$groupKey}";
    }

    /**
     * Sort groups by explicit order array. Groups not in the order array
     * are appended at the end in their original order.
     */
    private static function sortGroupsByOrder(array $groups, array $order): array
    {
        $orderMap = array_flip($order);
        $max = count($order);

        uksort($groups, function (string $a, string $b) use ($orderMap, $max) {
            $posA = $orderMap[$a] ?? ($max + 1);
            $posB = $orderMap[$b] ?? ($max + 1);

            return $posA <=> $posB;
        });

        return $groups;
    }

    // ─── Pruning ──────────────────────────────────────────────

    /**
     * Prune empty groups and non-clickable parents without children.
     */
    private static function pruneGroups(array $groups): array
    {
        foreach ($groups as $key => &$group) {
            $group['items'] = static::pruneTree($group['items']);

            if (empty($group['items'])) {
                unset($groups[$key]);
            }
        }

        return $groups;
    }

    /**
     * Prune rules:
     * - Non-clickable parent (empty to) with 0 children → removed
     * - Clickable parent with 0 children → kept (regular nav item)
     * - Non-clickable parent with children → kept as parent
     */
    private static function pruneTree(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            // Recursively prune children
            $node['children'] = static::pruneTree($node['children']);

            // Non-clickable parent with no children → prune
            if (empty($node['to']) && empty($node['children'])) {
                continue;
            }

            $result[] = $node;
        }

        return $result;
    }

    // ─── Validation ───────────────────────────────────────────

    private static function validateUniqueKeys(array $items): void
    {
        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item->key])) {
                throw new RuntimeException("Duplicate nav key: '{$item->key}'");
            }

            $seen[$item->key] = true;
        }
    }

    // ─── Output cleaning ──────────────────────────────────────

    /**
     * Clean internal fields from a tree node for JSON output.
     */
    private static function cleanNode(array $node): array
    {
        $children = collect($node['children'])
            ->sortBy('_sort')
            ->map(fn (array $child) => static::cleanNode($child))
            ->values()
            ->all();

        return [
            'key' => $node['key'],
            'title' => $node['title'],
            'to' => $node['to'],
            'icon' => $node['icon'],
            'permission' => $node['permission'],
            'children' => $children,
        ];
    }
}
