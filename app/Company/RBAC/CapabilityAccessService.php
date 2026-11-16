<?php

namespace App\Company\RBAC;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-803 / 803.1 — THE capability ↔ role engine of the existing RBAC. Used by the module assistant AND by
 * Équipe → Rôles : one behaviour, one policy (MembershipSecurityPolicy), one storage (role permissions).
 *
 * - A capability = a manifest bundle (the module is the only source of its permissions).
 * - The RBAC stores EFFECTIVE permissions, never the intent « granted through bundle X » : a capability is
 *   « held » when all its permissions are present, « partial » otherwise. Nothing is inferred from bundles.
 * - Only EXPLICIT cell changes are applied ; permissions outside the changed capabilities are never touched
 *   (historical customisations preserved).
 * - Revoking a capability keeps the permissions another fully-held capability needs (overlap), EXCEPT for
 *   capabilities contained in the revoked one ; any capability (any module) that would stop being fully held
 *   is listed as AFFECTED and the change requires an explicit `confirm_affected`.
 * - Mandatory preview + fingerprint (concurrency) ; every role change goes through the delegation policy.
 */
final class CapabilityAccessService
{
    public function __construct(private Company $company) {}

    /** Capabilities of one module (null = every company module : used for overlaps and side effects). */
    public function capabilities(?string $moduleKey): array
    {
        $defs = ModuleRegistry::definitions();
        $modules = $moduleKey ? [$moduleKey => $defs[$moduleKey] ?? abort(404)] : ModuleRegistry::forScope('company');
        [$owner, $inert] = self::permissionIndex();
        $out = [];
        foreach ($modules as $key => $m) {
            foreach ($m->bundles as $b) {
                $all = array_values($b['permissions'] ?? []);
                // ADR-807 — a composite capability may embed permissions of OTHER modules (e.g. workforce.manager
                // → Leave approval). Only the permissions of modules ACTIVE for this company are granted, revoked
                // or counted ; the others (« dormant ») are never attributed here, so activating a module later
                // never gives rights nobody decided. Historical pivots are left untouched.
                $dormant = array_values(array_filter($all, fn ($p) => ($owner[$p] ?? $key) !== $key && ! $this->moduleActive($owner[$p])));
                $set = array_values(array_diff($all, $dormant));
                $out[] = ['key' => $b['key'], 'module' => $key, 'label' => $b['label'] ?? $b['key'], 'hint' => $b['hint'] ?? null,
                    // ADR-804 : « sensible » = déclaré explicitement (rémunération, données personnelles) ; `is_admin`
                    // reste une CONTRAINTE (rôles de gestion seulement), pas une catégorie d'affichage.
                    'sensitive' => (bool) ($b['sensitive'] ?? false),
                    'administrative_only' => (bool) ($b['is_admin'] ?? false),
                    'scope' => $b['scope'] ?? 'collective',
                    'unwired' => (bool) ($b['unwired'] ?? false), // ADR-804 : aucune fonction ne l'utilise encore
                    'permission_count' => count($set),
                    'inert_permissions' => array_values(array_intersect($set, $inert)), // ADR-807 : guard nothing
                    'dormant_modules' => array_values(array_unique(array_map(fn ($p) => $owner[$p] ?? $key, $dormant))),
                    'permission_set' => $set];
            }
        }

        return $out;
    }

    /** @return array{0: array<string,string>, 1: list<string>} permission → declaring module ; inert permission keys. */
    public static function permissionIndex(): array
    {
        $owner = [];
        $inert = [];
        foreach (ModuleRegistry::definitions() as $key => $m) {
            foreach ($m->permissions ?? [] as $p) {
                $k = is_array($p) ? $p['key'] : $p;
                $owner[$k] = $key;
                if (is_array($p) && ($p['inert'] ?? false)) {
                    $inert[] = $k;
                }
            }
        }

        return [$owner, $inert];
    }

    /** @var array<string,bool> */
    private array $activeCache = [];

    private function moduleActive(string $moduleKey): bool
    {
        return $this->activeCache[$moduleKey] ??= ModuleGate::isActive($this->company, $moduleKey);
    }

    public function roles()
    {
        return CompanyRole::query()->where('company_id', $this->company->id)->with('permissions:id,key')->orderBy('name')->get();
    }

    /** @return array<int, list<string>> role id → permission keys (company roles only). */
    public static function rolePermissionKeys(Company $company): array
    {
        return CompanyRole::query()->where('company_id', $company->id)->with('permissions:id,key')->get()
            ->mapWithKeys(fn (CompanyRole $r) => [$r->id => $r->permissions->pluck('key')->all()])->all();
    }

    public static function holding(array $permissionKeys, array $cap): string
    {
        if (! $cap['permission_set']) {
            return 'none'; // ADR-807 : capability of an inactive module (nothing grantable)
        }
        $held = array_intersect($cap['permission_set'], $permissionKeys);

        return count($held) === count($cap['permission_set']) ? 'full' : (count($held) ? 'partial' : 'none');
    }

    /** Public view (no permission set, keys only in advanced mode on the client). */
    public function describe(string $moduleKey, User $actor): array
    {
        $caps = $this->capabilities($moduleKey);

        return [
            'capabilities' => array_values(array_map(fn ($c) => array_diff_key($c, ['permission_set' => 1]) + ['permissions' => $c['permission_set']], $caps)),
            'roles' => $this->roles()->map(fn (CompanyRole $r) => [
                'id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'is_administrative' => (bool) $r->is_administrative,
                'editable' => $r->key !== 'owner',
                'holds' => collect($caps)->mapWithKeys(fn ($c) => [$c['key'] => self::holding($r->permissions->pluck('key')->all(), $c)])->all(),
                // ADR-808 : stored but not effective until the module's access step is confirmed (shown, never hidden)
                'pending' => array_values(array_intersect(\App\Core\Modules\Setup\PendingModuleAccess::suspendedFor($r),
                    collect($caps)->flatMap(fn ($c) => $c['permission_set'])->all())),
            ])->values()->all(),
            'fingerprint' => $this->fingerprint($moduleKey),
            'can_change' => $actor->hasCompanyPermission($this->company, 'roles.manage'),
        ];
    }

    /** @param list<array{role_id:int, capability:string, grant:bool}> $changes */
    public function preview(string $moduleKey, array $changes, User $actor): array
    {
        $caps = collect($this->capabilities($moduleKey))->keyBy('key');
        $all = $this->capabilities(null);
        $roles = $this->roles()->keyBy('id');
        $byRole = [];
        foreach ($changes as $i => $c) {
            $role = $roles->get((int) ($c['role_id'] ?? 0));
            $cap = $caps->get((string) ($c['capability'] ?? ''));
            if (! $role || ! $cap) {
                throw ValidationException::withMessages(["changes.$i" => 'Unknown role or capability for this company and module.']);
            }
            $byRole[$role->id][$cap['key']] = ['cap' => $cap, 'grant' => (bool) ($c['grant'] ?? false)];
        }

        $plan = [];
        foreach ($byRole as $roleId => $items) {
            $role = $roles->get($roleId);
            $current = $role->permissions->pluck('key')->all();
            $after = $current;
            foreach ($items as $it) {
                if ($it['grant']) {
                    $after = array_values(array_unique(array_merge($after, $it['cap']['permission_set'])));
                }
            }
            foreach ($items as $it) {
                if (! $it['grant']) {
                    $keep = collect($all)->reject(fn ($o) => $o['key'] === $it['cap']['key'])
                        ->reject(fn ($o) => ! array_diff($o['permission_set'], $it['cap']['permission_set'])) // contained ⇒ implied
                        ->filter(fn ($o) => ! array_diff($o['permission_set'], $after))->flatMap(fn ($o) => $o['permission_set'])->all();
                    $after = array_values(array_diff($after, array_diff($it['cap']['permission_set'], $keep)));
                }
            }
            $adds = array_values(array_diff($after, $current));
            $removes = array_values(array_diff($current, $after));
            $changed = array_keys($items);
            $affected = collect($all)->reject(fn ($o) => in_array($o['key'], $changed, true))
                ->filter(fn ($o) => self::holding($current, $o) === 'full' && self::holding($after, $o) !== 'full')
                ->map(fn ($o) => ['key' => $o['key'], 'module' => $o['module'], 'label' => $o['label']])->values()->all();
            $completed = collect($all)->reject(fn ($o) => in_array($o['key'], $changed, true))
                ->filter(fn ($o) => self::holding($current, $o) !== 'full' && self::holding($after, $o) === 'full')
                ->map(fn ($o) => ['key' => $o['key'], 'module' => $o['module'], 'label' => $o['label']])->values()->all();
            $plan[] = [
                'role_id' => $role->id, 'role' => $role->name,
                'capabilities' => array_map(fn ($it) => ['key' => $it['cap']['key'], 'grant' => $it['grant']], array_values($items)),
                'adds' => count($adds), 'removes' => count($removes), 'add_keys' => $adds, 'remove_keys' => $removes,
                'affected' => $affected, 'also_completes' => $completed,
                'blocked' => MembershipSecurityPolicy::rolePermissionChangeViolation($this->company, $role, $adds, $removes, $actor),
            ];
        }

        return [
            'plan' => $plan,
            'fingerprint' => $this->fingerprint($moduleKey),
            'blocked' => collect($plan)->whereNotNull('blocked')->isNotEmpty(),
            'requires_confirmation' => collect($plan)->contains(fn ($p) => $p['affected'] !== []),
        ];
    }

    /** Applies a previewed change set. Returns the applied plan. */
    public function apply(string $moduleKey, array $changes, string $fingerprint, User $actor, bool $confirmAffected, string $source): array
    {
        return DB::transaction(function () use ($moduleKey, $changes, $fingerprint, $actor, $confirmAffected, $source) {
            CompanyRole::query()->where('company_id', $this->company->id)->lockForUpdate()->get(['id']); // serialise concurrent editors
            if (! hash_equals($this->fingerprint($moduleKey), $fingerprint)) {
                abort(response()->json(['message' => 'Access changed since the preview.', 'code' => 'stale_preview'], 409));
            }
            $preview = $this->preview($moduleKey, $changes, $actor);
            if ($preview['blocked']) {
                throw ValidationException::withMessages(['changes' => 'Some changes are not allowed.']);
            }
            if ($preview['requires_confirmation'] && ! $confirmAffected) {
                abort(response()->json(['message' => 'Other capabilities would be affected.', 'code' => 'affected_capabilities',
                    'plan' => $preview['plan']], 422));
            }
            $roles = $this->roles()->keyBy('id');
            foreach ($preview['plan'] as $p) {
                if (! $p['adds'] && ! $p['removes']) {
                    continue; // idempotent
                }
                $role = $roles->get($p['role_id']);
                MembershipSecurityPolicy::assertCanChangeRolePermissions($this->company, $role, $p['add_keys'], $p['remove_keys'], $actor);
                $keys = array_values(array_diff(array_unique(array_merge($role->permissions->pluck('key')->all(), $p['add_keys'])), $p['remove_keys']));
                $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $keys)->pluck('id')->all());
            }
            // ADR-808 : an explicit decision on a role for this module ends its pending (dormant) rights for the module.
            foreach ($preview['plan'] as $p) {
                \App\Core\Modules\Setup\PendingModuleAccess::release($this->company->id, $moduleKey, (int) $p['role_id']);
            }
            app(AuditLogger::class)->logCompany($this->company->id, 'role.capabilities_changed', 'module', $moduleKey, [
                'actorId' => $actor->id,
                'metadata' => ['source' => $source, 'changes' => collect($preview['plan'])->map(fn ($p) => array_intersect_key($p, array_flip(['role_id', 'capabilities', 'add_keys', 'remove_keys', 'affected'])))->all()],
            ]);

            return $preview['plan'];
        });
    }

    /** Concurrency fingerprint : the module's permission set on every role of the company. */
    public function fingerprint(string $moduleKey): string
    {
        $set = collect($this->capabilities($moduleKey))->flatMap(fn ($c) => $c['permission_set'])->unique()->all();

        return sha1(json_encode($this->roles()->mapWithKeys(fn ($r) => [$r->id => $r->permissions->pluck('key')->intersect($set)->sort()->values()->all()])->all()));
    }
}
