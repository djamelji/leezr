<?php

namespace App\Modules\Platform\Activity;

/**
 * ADR-440: Maps audit action prefixes to feed categories.
 */
final class ActivityCategoryMap
{
    private const MAP = [
        'auth' => ['auth.', 'platform_auth.'],
        'billing' => ['billing.', 'subscription.', 'webhook.'],
        'admin' => ['platform_user.', 'field.', 'realtime.', 'security.'],
        'company' => ['company.', 'role.', 'member.', 'user.', 'theme.', 'plan.', 'jobdomain.'],
        'support' => ['support.'],
        'module' => ['module.'],
        'document' => ['document.', 'document_type.'],
    ];

    public static function prefixesFor(string $category): ?array
    {
        return self::MAP[$category] ?? null;
    }

    public static function categories(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Categorize an action string into a feed category.
     */
    public static function categorize(string $action): string
    {
        foreach (self::MAP as $category => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $category;
                }
            }
        }

        return 'admin';
    }
}
