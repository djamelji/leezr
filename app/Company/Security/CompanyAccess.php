<?php

namespace App\Company\Security;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;

/**
 * Unified company access layer — single source of truth for all
 * company-scoped authorization checks.
 *
 * Abilities:
 *   access-surface   — surface separation (structure vs operations)
 *   use-module        — module activation check
 *   use-permission    — RBAC permission check
 *   manage-structure  — governance access (administrative role required)
 */
class CompanyAccess
{
    /**
     * @param  string  $ability   One of: access-surface, use-module, use-permission, manage-structure
     * @param  array   $context   Ability-specific context (e.g. ['surface' => 'structure'])
     */
    public static function can(User $user, Company $company, string $ability, array $context = []): bool
    {
        // Module check has NO owner bypass — inactive module = no data
        if ($ability === 'use-module') {
            return static::checkModule($company, $context['module'] ?? null);
        }

        // Owner bypasses all other abilities
        if ($user->isOwnerOf($company)) {
            return true;
        }

        return match ($ability) {
            'access-surface' => static::checkSurface($user, $company, $context['surface'] ?? null),
            'use-permission' => static::checkPermission($user, $company, $context['permission'] ?? null),
            'manage-structure' => static::checkAdministrative($user, $company),
            default => false,
        };
    }

    /**
     * Surface check: 'structure' requires administrative role.
     */
    private static function checkSurface(User $user, Company $company, ?string $surface): bool
    {
        if ($surface !== 'structure') {
            return true;
        }

        return static::checkAdministrative($user, $company);
    }

    /**
     * Module check: module must be active for the company.
     */
    private static function checkModule(Company $company, ?string $moduleKey): bool
    {
        if (!$moduleKey) {
            return false;
        }

        return ModuleGate::isActive($company, $moduleKey);
    }

    /**
     * Permission check: user must hold the permission via their company role.
     */
    private static function checkPermission(User $user, Company $company, ?string $permission): bool
    {
        if (!$permission) {
            return false;
        }

        return $user->hasCompanyPermission($company, $permission);
    }

    /**
     * Administrative check: membership must have an administrative company role.
     */
    private static function checkAdministrative(User $user, Company $company): bool
    {
        $membership = $user->membershipFor($company);

        if (!$membership || !$membership->company_role_id) {
            return false;
        }

        return (bool) $membership->companyRole->is_administrative;
    }
}
