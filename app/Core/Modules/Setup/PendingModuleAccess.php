<?php

namespace App\Core\Modules\Setup;

use App\Company\RBAC\CapabilityAccessService;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;

/**
 * ADR-808 — rights a role ALREADY held when a module is activated for the first time (« dormant »), or that a
 * versioned creation pre-configured on operational roles, are NOT effective until the module's access step is
 * confirmed in its assistant (or the role is explicitly changed). Nothing is removed from the RBAC pivots :
 * the permission stays stored, it is only not honoured meanwhile. The owner role is never suspended.
 *
 * Storage : company_module_setups.pending_access = {role_id: [permission keys of THAT module]}.
 * Consumers : CompanyRole::hasPermission / effectivePermissionKeys (API checks and the permission list sent to
 * the client), so the screens, the menus and the API agree.
 */
final class PendingModuleAccess
{
    /** @var array<int, array<int, list<string>>> company id → role id → suspended keys */
    private static array $cache = [];

    /** Keys declared by the module (its own permissions — a composite capability's foreign keys belong to their module). */
    public static function moduleKeys(string $moduleKey): array
    {
        [$owner] = CapabilityAccessService::permissionIndex();

        return array_keys(array_filter($owner, fn ($m) => $m === $moduleKey));
    }

    /** What non-owner roles of the company currently hold among the module's permissions. */
    public static function snapshot(Company $company, string $moduleKey): array
    {
        $keys = self::moduleKeys($moduleKey);
        if (! $keys) {
            return [];
        }

        return CompanyRole::query()->where('company_id', $company->id)->where('key', '!=', 'owner')
            ->with(['permissions' => fn ($q) => $q->whereIn('key', $keys)->select('company_permissions.id', 'key')])->get()
            ->mapWithKeys(fn (CompanyRole $r) => [$r->id => $r->permissions->pluck('key')->sort()->values()->all()])
            ->filter()->all();
    }

    /** @return list<string> keys of this role that are stored but not effective yet. */
    public static function suspendedFor(CompanyRole $role): array
    {
        $map = self::$cache[$role->company_id] ??= self::load($role->company_id);

        return $map[$role->id] ?? [];
    }

    /** Explicit decision taken for one role (Équipe → Rôles, role editor) : its pending keys of that module become plain rights. */
    public static function release(int $companyId, ?string $moduleKey, int $roleId): void
    {
        CompanyModuleSetup::query()->where('company_id', $companyId)->whereNotNull('pending_access')
            ->when($moduleKey, fn ($q) => $q->where('module_key', $moduleKey))->get()
            ->each(function (CompanyModuleSetup $row) use ($roleId) {
                $pending = $row->pending_access ?? [];
                unset($pending[$roleId], $pending[(string) $roleId]);
                $row->forceFill(['pending_access' => $pending ?: null])->save();
            });
    }

    /** The access step was confirmed in the assistant : the holders shown there are decided. */
    public static function confirmAll(int $companyId, string $moduleKey): void
    {
        CompanyModuleSetup::query()->where('company_id', $companyId)->where('module_key', $moduleKey)
            ->whereNotNull('pending_access')->update(['pending_access' => null]);
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function load(int $companyId): array
    {
        $out = [];
        foreach (CompanyModuleSetup::query()->where('company_id', $companyId)->whereNotNull('pending_access')->pluck('pending_access') as $pending) {
            foreach ((array) $pending as $roleId => $keys) {
                $out[(int) $roleId] = array_values(array_unique(array_merge($out[(int) $roleId] ?? [], (array) $keys)));
            }
        }

        return $out;
    }
}
