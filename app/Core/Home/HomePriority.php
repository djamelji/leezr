<?php

namespace App\Core\Home;

/** ADR-776 — one « À traiter » item. Label = i18n key `home.priorities.<key>` on the frontend. */
final class HomePriority
{
    public const SEVERITIES = ['error', 'warning', 'info'];

    /**
     * @param  string  $key       unique across modules
     * @param  string  $space     intent space the item belongs to (NavGroupRegistry)
     * @param  array   $to        route location opening the treatment ({name, params?, query?})
     */
    public static function make(string $key, int $count, string $severity, string $icon, array $to, string $space): array
    {
        return ['key' => $key, 'count' => $count, 'severity' => $severity, 'icon' => $icon, 'to' => $to, 'space' => $space];
    }
}
