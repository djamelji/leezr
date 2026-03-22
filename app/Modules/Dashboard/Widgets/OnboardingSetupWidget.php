<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-383: Pipeline-driven onboarding widget — owner-only, dismissible.
 * Backend enforces owner guard (403 for non-owners).
 * Client-resolved — frontend handles display from onboarding API.
 */
class OnboardingSetupWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'onboarding.setup';
    }

    public function module(): string
    {
        return 'core.dashboard';
    }

    public function layout(): array
    {
        return [
            'default_w' => 12,
            'default_h' => 2,
            'min_w' => 12,
            'max_w' => 12,
            'min_h' => 2,
            'max_h' => 2,
        ];
    }

    public function category(): string
    {
        return 'onboarding';
    }

    public function component(): string
    {
        return 'OnboardingSetup';
    }

    public function tags(): array
    {
        return ['onboarding', 'setup', 'getting-started'];
    }

    public function labelKey(): string
    {
        return 'dashboard.widgets.onboarding';
    }

    public function descriptionKey(): string
    {
        return 'dashboard.widgets.onboardingDesc';
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
        return ['settings.view'];
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
        return ['management'];
    }
}
