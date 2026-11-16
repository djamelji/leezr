<?php

namespace App\Core\Modules\Setup;

use App\Company\RBAC\CapabilityAccessService;
use App\Company\RBAC\CompanyRole;
use App\Company\RBAC\MembershipSecurityPolicy;
use App\Core\Audit\AuditLogger;
use App\Core\Models\User;
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

    /**
     * ADR-808.1 — THE confirmation of pending rights (assistant access step, Équipe → Rôles, role editor). Each
     * role is checked with the existing delegation policy as if its pending keys were ADDED now : an actor never
     * makes effective a right he could not grant (beyond his rights, his own role, an operational role and an
     * administrative key…). Refused roles stay pending. Pivots are never changed. Audited.
     *
     * @return array{confirmed: list<array{role_id:int, module:string, keys:list<string>}>, refused: list<array{role_id:int, module:string, code:string}>}
     */
    public static function confirm(Company $company, ?User $actor, ?string $moduleKey = null, ?int $roleId = null, string $source = 'assistant'): array
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => self::doConfirm($company, $actor, $moduleKey, $roleId, $source));
    }

    private static function doConfirm(Company $company, ?User $actor, ?string $moduleKey, ?int $roleId, string $source): array
    {
        $out = ['confirmed' => [], 'refused' => []];
        $rows = CompanyModuleSetup::query()->where('company_id', $company->id)->whereNotNull('pending_access')
            ->when($moduleKey, fn ($q) => $q->where('module_key', $moduleKey))->lockForUpdate()->get();
        foreach ($rows as $row) {
            $pending = $row->pending_access ?? [];
            foreach ($pending as $rid => $keys) {
                if ($roleId !== null && (int) $rid !== $roleId) {
                    continue;
                }
                $role = CompanyRole::query()->where('company_id', $company->id)->find((int) $rid);
                if ($role && ($code = MembershipSecurityPolicy::rolePermissionChangeViolation($company, $role, array_values((array) $keys), [], $actor))) {
                    $out['refused'][] = ['role_id' => (int) $rid, 'module' => $row->module_key, 'code' => $code];

                    continue;
                }
                unset($pending[$rid]);
                $out['confirmed'][] = ['role_id' => (int) $rid, 'module' => $row->module_key, 'keys' => array_values((array) $keys)];
            }
            $row->forceFill(['pending_access' => $pending ?: null])->save();
        }
        if ($out['confirmed'] || $out['refused']) {
            app(AuditLogger::class)->logCompany($company->id, 'role.pending_confirmed', 'module', $moduleKey ?? '*', [
                'actorId' => $actor?->id, 'metadata' => $out + ['source' => $source]]);
        }
        self::flush();

        return $out;
    }

    /**
     * ADR-809 — grouped review : per role, the BUSINESS capabilities its pending rights complete (labels are
     * translated client-side), sensitive / administrative ones apart, and whether THIS actor may confirm them
     * (delegation policy). The fingerprint binds the confirmation to exactly what was shown (no unseen right).
     */
    public static function summary(Company $company, ?User $actor): array
    {
        $caps = collect((new CapabilityAccessService($company))->capabilities(null));
        $roles = [];
        foreach (CompanyRole::query()->where('company_id', $company->id)->with('permissions:id,key')->orderBy('name')->get() as $role) {
            $pending = self::suspendedFor($role);
            if (! $pending) {
                continue;
            }
            $held = $role->permissions->pluck('key')->all();
            $covered = $caps->filter(fn ($c) => $c['permission_set'] && array_intersect($c['permission_set'], $pending) && ! array_diff($c['permission_set'], $held));
            // keep the widest capabilities only (one contained in another is implied)
            $covered = $covered->reject(fn ($c) => $covered->contains(fn ($o) => $o['key'] !== $c['key'] && ! array_diff($c['permission_set'], $o['permission_set']) && array_diff($o['permission_set'], $c['permission_set'])));
            $listed = $covered->flatMap(fn ($c) => $c['permission_set'])->all();
            $roles[] = ['id' => $role->id, 'name' => $role->name, 'is_administrative' => (bool) $role->is_administrative,
                'capabilities' => $covered->map(fn ($c) => ['key' => $c['key'], 'module' => $c['module'], 'label' => $c['label'],
                    'sensitive' => $c['sensitive'] || $c['administrative_only']])->values()->all(),
                'other_rights' => count(array_diff($pending, $listed)), // pending keys outside any complete capability
                'blocked' => MembershipSecurityPolicy::rolePermissionChangeViolation($company, $role, $pending, [], $actor)];
        }

        return ['roles' => $roles, 'fingerprint' => self::fingerprint($company)];
    }

    public static function fingerprint(Company $company): string
    {
        return sha1(json_encode(CompanyModuleSetup::query()->where('company_id', $company->id)->whereNotNull('pending_access')
            ->orderBy('module_key')->get(['module_key', 'pending_access'])->map(fn ($r) => [$r->module_key, $r->pending_access])->all()));
    }

    /** Pending keys of one role, grouped by module (role editor, Équipe → Rôles). */
    public static function byModule(CompanyRole $role): array
    {
        return CompanyModuleSetup::query()->where('company_id', $role->company_id)->whereNotNull('pending_access')->get()
            ->mapWithKeys(fn ($r) => [$r->module_key => array_values((array) (($r->pending_access ?? [])[$role->id] ?? ($r->pending_access ?? [])[(string) $role->id] ?? []))])
            ->filter()->all();
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
