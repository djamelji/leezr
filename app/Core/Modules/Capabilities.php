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
        public readonly array $headerWidgets = [],
        public readonly array $settingsPanels = [],
        public readonly array $footerLinks = [],
    ) {}

    public function toArray(): array
    {
        return [
            'nav_items' => $this->navItems,
            'route_names' => $this->routeNames,
            'middleware_key' => $this->middlewareKey,
            'header_widgets' => $this->headerWidgets,
            'settings_panels' => $this->settingsPanels,
            'footer_links' => $this->footerLinks,
        ];
    }
}
