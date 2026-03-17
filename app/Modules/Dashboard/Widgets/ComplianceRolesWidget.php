<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class ComplianceRolesWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'compliance.roles';
    }

    public function module(): string
    {
        return 'core.dashboard';
    }

    public function layout(): array
    {
        return [
            'default_w' => 6,
            'default_h' => 4,
            'min_w' => 4,
            'max_w' => 12,
            'min_h' => 3,
            'max_h' => 8,
        ];
    }

    public function category(): string
    {
        return 'compliance';
    }

    public function component(): string
    {
        return 'ComplianceRoles';
    }

    public function tags(): array
    {
        return ['compliance', 'roles', 'list'];
    }

    public function labelKey(): string
    {
        return 'compliance.widgets.roles';
    }

    public function descriptionKey(): string
    {
        return 'compliance.widgets.rolesDesc';
    }

    public function audience(): string
    {
        return 'company';
    }

    public function scope(): string
    {
        return 'company';
    }

    public function resolution(): string
    {
        return 'client';
    }

    public function permissions(): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return [];
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function resolve(array $context): array
    {
        return [];
    }

    public function archetypes(): ?array
    {
        return null;
    }
}
