<?php

namespace App\Core\Modules;

/**
 * Immutable value object representing a module's declared capabilities.
 */
final class Capabilities
{
    public function __construct(
        public readonly array $navItems = [],
        public readonly array $routeNames = [],
        public readonly ?string $middlewareKey = null,
    ) {}

    public function toArray(): array
    {
        return [
            'nav_items' => $this->navItems,
            'route_names' => $this->routeNames,
            'middleware_key' => $this->middlewareKey,
        ];
    }
}
