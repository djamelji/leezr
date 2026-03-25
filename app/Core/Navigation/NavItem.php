<?php

namespace App\Core\Navigation;

/**
 * Immutable value object for a single navigation item.
 *
 * Created from module manifest navItem arrays via fromManifestArray().
 * Carries all metadata needed by the NavBuilder pipeline:
 * filtering (permission, surface, plans, jobdomains, roleLevel),
 * grouping (group), nesting (parent), and ordering (sort).
 */
final class NavItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $to,
        public readonly string $icon,
        public readonly ?string $permission = null,
        public readonly ?string $surface = null,
        public readonly bool $operationalOnly = false,
        public readonly ?string $group = null,
        public readonly ?string $parent = null,
        public readonly ?array $jobdomains = null,
        public readonly ?array $plans = null,
        public readonly ?array $tags = null,
        public readonly int $sort = 0,
        public readonly ?string $excludePermission = null,
    ) {}

    public static function fromManifestArray(array $data, int $moduleSortOrder = 0): self
    {
        return new self(
            key: $data['key'],
            title: $data['title'],
            to: $data['to'] ?? [],
            icon: $data['icon'] ?? 'tabler-puzzle',
            permission: $data['permission'] ?? null,
            surface: $data['surface'] ?? null,
            operationalOnly: $data['operationalOnly'] ?? false,
            group: $data['group'] ?? null,
            parent: $data['parent'] ?? null,
            jobdomains: $data['jobdomains'] ?? null,
            plans: $data['plans'] ?? null,
            tags: $data['tags'] ?? null,
            sort: $data['sort'] ?? $moduleSortOrder,
            excludePermission: $data['excludePermission'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'to' => $this->to,
            'icon' => $this->icon,
            'permission' => $this->permission,
            'surface' => $this->surface,
            'operationalOnly' => $this->operationalOnly,
            'group' => $this->group,
            'parent' => $this->parent,
            'jobdomains' => $this->jobdomains,
            'plans' => $this->plans,
            'tags' => $this->tags,
            'sort' => $this->sort,
        ];
    }

    public function isClickable(): bool
    {
        return !empty($this->to);
    }
}
