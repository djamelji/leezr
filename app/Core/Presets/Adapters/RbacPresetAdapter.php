<?php

namespace App\Core\Presets\Adapters;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;

/**
 * ADR-793 (L3) — RBAC family. Content : {roles: [{key, name, is_administrative?, bundles: [..]}]} —
 * permissions only through EXISTING bundles (module manifests), never free keys.
 *
 * Security (ADR-752, ADR-788) : an EXISTING company role is NEVER modified (difference reported only) ;
 * a missing role is created on apply, but a role that is administrative or carries a sensitive
 * permission (catalogue `is_admin`) needs an explicit selection (`requires_confirmation`) — never
 * created by default. No permission is ever removed.
 */
final class RbacPresetAdapter implements PresetFamilyAdapter
{
    public function kind(): string
    {
        return 'rbac';
    }

    public function validate(array $content, ?string $marketKey = null): void
    {
        $errors = [];
        $known = collect(ModuleRegistry::forScope('company'))->flatMap(fn ($m) => array_column($m->bundles, 'key'))->all();
        foreach ($content['roles'] ?? [] as $i => $role) {
            if (! preg_match('/^[a-z0-9_]{2,60}$/', (string) ($role['key'] ?? ''))) {
                $errors["roles.{$i}.key"] = 'Invalid role key.';
            }
            if (blank($role['name'] ?? null)) {
                $errors["roles.{$i}.name"] = 'A role needs a name.';
            }
            if ($unknown = array_diff($role['bundles'] ?? [], $known)) {
                $errors["roles.{$i}.bundles"] = 'Unknown bundles: '.implode(', ', $unknown);
            }
        }
        if (empty($content['roles'])) {
            $errors['roles'] = 'An RBAC preset needs at least one role.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function items(array $content): array
    {
        $out = [];
        foreach ($content['roles'] ?? [] as $role) {
            $role['permissions'] = $this->permissions($role);
            $out['role:'.$role['key']] = $role;
        }

        return $out;
    }

    public function onPublish(object $preset): void {}

    public function preview(Company $company, object $preset): array
    {
        $existing = CompanyRole::where('company_id', $company->id)->with('permissions:id,key')->get()->keyBy('key');
        $sensitive = CompanyPermission::where('is_admin', true)->pluck('key')->all();
        $out = [];
        foreach ($this->items($preset->content) as $key => $role) {
            $current = $existing->get($role['key']);
            $wanted = $role['permissions'];
            $sensitiveWanted = array_values(array_intersect($wanted, $sensitive));
            if ($current) {
                $have = $current->permissions->pluck('key')->all();
                $add = array_values(array_diff($wanted, $have));
                $remove = array_values(array_diff($have, $wanted));
                [$status, $detail] = ($add === [] && $remove === [])
                    ? ['identical', null]
                    : ['customized', 'existing_role_unchanged; preset_adds:'.count($add).'; company_has_more:'.count($remove).($add && array_intersect($add, $sensitive) ? '; includes_sensitive' : '')];
            } else {
                [$status, $detail] = (($role['is_administrative'] ?? false) || $sensitiveWanted)
                    ? ['requires_confirmation', 'sensitive_permissions:'.count($sensitiveWanted).(($role['is_administrative'] ?? false) ? '; administrative' : '')]
                    : ['new', null];
            }
            $out[] = ['key' => $key, 'label' => $role['name'], 'status' => $status, 'detail' => $detail];
        }

        return $out;
    }

    public function apply(Company $company, object $preset, ?array $selected, ?int $actorId): array
    {
        $done = ['created' => [], 'skipped' => []];
        $items = $this->items($preset->content);
        foreach ($this->preview($company, $preset) as $p) {
            $explicit = $selected !== null && in_array($p['key'], $selected, true);
            $allowed = $p['status'] === 'new' ? ($selected === null || $explicit) : ($p['status'] === 'requires_confirmation' && $explicit);
            if (! $allowed) {
                $done['skipped'][] = $p['key']; // rôle existant jamais modifié ; sensible jamais par défaut

                continue;
            }
            $role = $items[$p['key']];
            $created = CompanyRole::create(['company_id' => $company->id, 'key' => $role['key'], 'name' => $role['name'], 'is_system' => false,
                'is_administrative' => (bool) ($role['is_administrative'] ?? false)]);
            $created->permissions()->sync(CompanyPermission::whereIn('key', $role['permissions'])->pluck('id')->all());
            $done['created'][] = $p['key'];
        }

        return $done;
    }

    private function permissions(array $role): array
    {
        $keys = array_values(array_unique(ModuleRegistry::resolveBundles($role['bundles'] ?? [])));
        sort($keys);

        return $keys;
    }
}
