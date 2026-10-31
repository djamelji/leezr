<?php

namespace App\Core\Navigation;

/**
 * ADR-596 (LOT 2) — single source of truth for navigation groups.
 *
 * Before: group identity/order lived in THREE unsynchronized places (manifest `group` strings,
 * NavBuilder::ADMIN_GROUP_ORDER, and i18n `nav.groups.*`), with no company ordering and a dead
 * `product` group. This registry is now the ONLY place that declares which groups exist, their
 * scope and their display order. Modules keep assigning items to a group via its stable key
 * (capabilities.navItems `group`), and several modules may share the same group. i18n
 * (`nav.groups.<key>`) remains purely the language resource for the heading label.
 *
 * This is NOT a second navigation engine: NavBuilder stays the only engine and simply reads
 * ordering/title/validation from here.
 */
class NavGroupRegistry
{
    /**
     * scope => [ groupKey => displayOrder ]. Lower order renders first.
     * A high fallback order (900) is reserved for the scope's implicit default bucket.
     */
    private const GROUPS = [
        // ADR-598 (Phase 2 LOT A): canonical 6-domain platform taxonomy.
        'admin' => [
            'cockpit' => 10,       // dashboard, messaging
            'clients' => 20,       // companies, support
            'finance' => 30,       // billing
            'governance' => 40,    // module catalog, plans, jobdomains, access/roles
            'configuration' => 50, // settings, international/markets
            'system' => 60,        // operations: health, alerts, security, ai, usage, automations, realtime
            'management' => 900,   // default bucket for admin items without an explicit group
        ],
        'company' => [
            'identity' => 10,
            'contact' => 20,
            'dispatch' => 30,
            'driving' => 40,
            // W-HR-UX-7 : RH regroupée par TÂCHE (sections visibles, sans sous-menus repliables →
            // zéro clic supplémentaire). Remplace l'unique bloc « Ressources humaines » de 11 items.
            'hr_team' => 50,       // Collaborateurs
            'hr_time' => 51,       // Temps & planning
            'hr_absences' => 52,   // Absences
            'hr_payroll' => 53,    // Paie & déclarations
            'hr_documents' => 54,  // Documents RH
            'hr_settings' => 55,   // Paramètres RH
            'hr' => 56,            // fallback historique (surface:'hr' sans group explicite) — élagué si vide
            'account' => 60,
            'operations' => 70,
            'company' => 900, // default bucket for company items without an explicit group
        ],
    ];

    /**
     * Whether a group key is registered for the given scope.
     */
    public static function has(string $scope, string $groupKey): bool
    {
        return isset(self::GROUPS[$scope][$groupKey]);
    }

    /**
     * Ordered list of group keys for a scope (ascending display order).
     *
     * @return array<string>
     */
    public static function orderedKeys(string $scope): array
    {
        $groups = self::GROUPS[$scope] ?? [];
        asort($groups);

        return array_keys($groups);
    }

    /**
     * All registered group keys for a scope (unordered).
     *
     * @return array<string>
     */
    public static function keys(string $scope): array
    {
        return array_keys(self::GROUPS[$scope] ?? []);
    }

    /**
     * i18n translation key for a group heading. Empty for the root/unnamed group.
     */
    public static function titleKey(string $groupKey): string
    {
        if ($groupKey === '' || $groupKey === 'root') {
            return '';
        }

        return "nav.groups.{$groupKey}";
    }
}
