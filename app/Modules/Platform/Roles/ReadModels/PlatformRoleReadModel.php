<?php

namespace App\Modules\Platform\Roles\ReadModels;

use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use Illuminate\Support\Collection;

class PlatformRoleReadModel
{
    private const SYSTEM_ROLE_KEYS = ['super_admin', 'admin'];

    private const USERS_SAMPLE_LIMIT = 4;

    public static function catalog(): array
    {
        $roles = PlatformRole::withCount('users')
            ->with('permissions:id,key,label,module_key,is_admin')
            ->with(['users' => fn ($q) => $q->select('platform_users.id', 'first_name', 'last_name')->limit(self::USERS_SAMPLE_LIMIT)])
            ->orderByRaw("CASE WHEN \"key\"='super_admin' THEN 0 WHEN \"key\"='admin' THEN 1 ELSE 2 END, \"key\" ASC")
            ->get();

        $allPermissions = PlatformPermission::all();
        $moduleMeta = self::buildModuleMeta();

        return $roles->map(fn (PlatformRole $role) => self::transform(
            $role,
            $allPermissions,
            $moduleMeta,
        ))->toArray();
    }

    public static function enrich(PlatformRole $role): array
    {
        $role->loadMissing('permissions:id,key,label,module_key,is_admin');
        $role->loadCount('users');
        $role->loadMissing(['users' => fn ($q) => $q->select('platform_users.id', 'first_name', 'last_name')->limit(self::USERS_SAMPLE_LIMIT)]);

        return self::transform(
            $role,
            PlatformPermission::all(),
            self::buildModuleMeta(),
        );
    }

    private static function transform(
        PlatformRole $role,
        Collection $allPermissions,
        Collection $moduleMeta,
    ): array {
        $isSuperAdmin = $role->key === 'super_admin';

        // super_admin has no pivot rows but structurally owns ALL permissions
        $permissions = $isSuperAdmin ? $allPermissions : $role->permissions;

        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'is_system' => in_array($role->key, self::SYSTEM_ROLE_KEYS, true),
            'access_level' => self::computeAccessLevel($role, $permissions, $allPermissions),
            'permissions_count' => $permissions->count(),
            'users_count' => $role->users_count,
            'users_sample' => $role->relationLoaded('users')
                ? $role->users->take(self::USERS_SAMPLE_LIMIT)->map(fn ($u) => [
                    'initials' => mb_strtoupper(mb_substr($u->first_name, 0, 1).mb_substr($u->last_name, 0, 1)),
                    'name' => trim($u->first_name.' '.$u->last_name),
                ])->values()->toArray()
                : [],
            'permissions' => $permissions->map(fn ($p) => [
                'id' => $p->id,
                'key' => $p->key,
                'label' => $p->label,
                'module_key' => $p->module_key,
                'is_admin' => (bool) $p->is_admin,
            ])->values()->toArray(),
            'permissions_grouped' => self::groupByModule($permissions, $moduleMeta),
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }

    private static function computeAccessLevel(
        PlatformRole $role,
        Collection $permissions,
        Collection $allPermissions,
    ): string {
        if ($role->key === 'super_admin') {
            return 'full_access';
        }

        $count = $permissions->count();
        $total = $allPermissions->count();

        if ($count === 0) {
            return 'custom';
        }

        if ($count === $total) {
            return 'administration';
        }

        $adminHeld = $permissions->where('is_admin', true)->count();

        if ($adminHeld > 0) {
            return 'management';
        }

        $totalNonAdmin = $allPermissions->where('is_admin', false)->count();

        if ($totalNonAdmin > 0 && $count < ($totalNonAdmin * 0.5)) {
            return 'limited';
        }

        return 'standard';
    }

    private static function groupByModule(Collection $permissions, Collection $moduleMeta): array
    {
        return $permissions
            ->groupBy('module_key')
            ->map(function (Collection $perms, string $moduleKey) use ($moduleMeta) {
                $meta = $moduleMeta->get($moduleKey, [
                    'module_name' => $moduleKey,
                    'module_icon' => 'tabler-puzzle',
                ]);

                return [
                    'module_key' => $moduleKey,
                    'module_name' => $meta['module_name'],
                    'module_icon' => $meta['module_icon'],
                    'permissions' => $perms->map(fn ($p) => [
                        'id' => $p->id,
                        'key' => $p->key,
                        'label' => $p->label,
                        'is_admin' => (bool) $p->is_admin,
                    ])->values()->toArray(),
                ];
            })
            ->sortBy('module_name')
            ->values()
            ->toArray();
    }

    private static function buildModuleMeta(): Collection
    {
        return collect(ModuleRegistry::forScope('admin'))
            ->mapWithKeys(fn ($manifest, $key) => [
                $key => [
                    'module_name' => $manifest->name,
                    'module_icon' => $manifest->iconRef,
                ],
            ]);
    }
}
