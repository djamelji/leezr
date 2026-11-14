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
            // ADR-775 (Company Experience 2.0 / UX0) : navigation par INTENTION. Les modules
            // contribuent à ces espaces (`group`) ; l'ordre et la présentation vivent ici.
            'home' => 1, 'team' => 2, 'time' => 3, 'documents' => 4, 'payroll' => 5,
            'activity' => 6, 'modules' => 7, 'settings' => 8, 'personal' => 9,
            // Groupes historiques : restent enregistrés (compat), vides donc élagués.
            'identity' => 10,
            'contact' => 20,
            'dispatch' => 30,
            'driving' => 40,
            // WORKFORCE EXPERIENCE 2026 / E2 (ADR-690) : navigation par ACTIVITÉ — un seul espace
            // « Ressources humaines » (entrées : Collaborateurs, Temps, Congés, Documents, Paie,
            // Organisation, Paramètres), gating fin par capacité (anyPermission). Remplace les 6
            // groupes mono-item W-HR-UX-7. Les groupes hr_* restent définis (compat/élagués si vides).
            'workforce' => 50,
            'hr_team' => 51, 'hr_time' => 52, 'hr_absences' => 53, 'hr_payroll' => 54,
            'hr_documents' => 55, 'hr_settings' => 56, 'hr' => 57,
            'account' => 60,
            'operations' => 70,
            'company' => 900, // default bucket for company items without an explicit group
        ],
    ];

    /**
     * ADR-775 — INTENT SPACES of the company scope. A space is ONE main entry of the menu :
     *   - one visible item  → that item is the entry (labelled by the space when `single` = 'space') ;
     *   - several items     → one entry labelled by the space, the items become its sub-entries ;
     *   - no item           → nothing (never an empty section).
     * `personal` : the user's own space — a visible entry for people without management entries,
     * the avatar menu for the others. `management` : the space counts as a management entry.
     * Nothing here names a module, a role or a jobdomain : modules choose a space, RBAC filters.
     */
    private const COMPANY_SPACES = [
        'home' => ['icon' => 'tabler-smart-home'],
        'team' => ['icon' => 'tabler-users', 'management' => true],
        // `time` is not a management marker : a plain employee may hold his own timesheet entry.
        'time' => ['icon' => 'tabler-calendar-time'],
        'documents' => ['icon' => 'tabler-files', 'management' => true],
        'payroll' => ['icon' => 'tabler-receipt', 'management' => true],
        'activity' => ['icon' => 'tabler-briefcase'],
        // ADR-782 (UX6) — Modules : products the company discovers, activates or subscribes to.
        // An intent space of its own (never a setting) ; RBAC (`modules.manage`) decides who sees it.
        'modules' => ['icon' => 'tabler-puzzle', 'management' => true, 'single' => 'space'],
        // ADR-781 (UX5) — Settings is a HUB : one menu entry opening `company-settings`, where the
        // entries contributed by the modules are presented by section and level.
        'settings' => ['icon' => 'tabler-settings', 'management' => true, 'single' => 'space', 'hub' => 'company-settings'],
        'personal' => ['icon' => 'tabler-user-circle', 'personal' => true],
    ];

    /** @return array{icon: string, management?: bool, single?: string, personal?: bool, hub?: string}|null */
    public static function space(string $groupKey): ?array
    {
        return self::COMPANY_SPACES[$groupKey] ?? null;
    }

    /** @return array<string> */
    public static function spaceKeys(): array
    {
        return array_keys(self::COMPANY_SPACES);
    }

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
