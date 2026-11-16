<?php

namespace App\Core\Modules\Setup;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Presets\PresetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-803 — guided module configuration : « who can do what » → module settings → confirmation.
 *
 * Pure orchestration of existing engines :
 *  - capabilities = the module's manifest `bundles` (the module stays the ONLY source of its permissions) ;
 *  - rights are written to the existing RBAC (CompanyRole::syncPermissionsSafe), never created ;
 *  - suggestions = the RBAC preset recommended for the company's JobDomain (proposed, never applied silently) ;
 *  - activation stays ModuleActivationEngine's job ; this service never activates, buys or bills anything.
 *
 * Guarantees : explicit cell changes only (no implicit widening), mandatory preview + fingerprint (concurrency),
 * an actor can only grant what they hold themselves (owner excepted), sensitive bundles only on administrative
 * roles (syncPermissionsSafe), roles of the current company only, the owner role is never edited, idempotent.
 */
final class ModuleSetupService
{
    public function __construct(private Company $company) {}

    public static function contract(string $moduleKey): ?array
    {
        $m = ModuleRegistry::definitions()[$moduleKey] ?? null;

        return $m && ! empty($m->setup['steps']) ? $m->setup : null;
    }

    /** Displayed state : available | to_configure | in_progress | ready | suspended | legacy (activated before ADR-803). */
    public function status(string $moduleKey): ?string
    {
        if (! self::contract($moduleKey)) {
            return null;
        }
        $row = CompanyModuleSetup::query()->where('company_id', $this->company->id)->where('module_key', $moduleKey)->first();
        $active = ModuleGate::isActive($this->company, $moduleKey);
        if (! $active) {
            return $row ? 'suspended' : 'available';
        }

        return $row?->status ?? 'legacy';
    }

    public function row(string $moduleKey): CompanyModuleSetup
    {
        return CompanyModuleSetup::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'module_key' => $moduleKey],
            ['status' => CompanyModuleSetup::TO_CONFIGURE, 'completed_steps' => []],
        );
    }

    // ── describe ──────────────────────────────────────────────────────────────────────────────────

    public function describe(string $moduleKey, User $actor): array
    {
        $contract = self::contract($moduleKey) ?? abort(404);
        $manifest = ModuleRegistry::definitions()[$moduleKey];
        $row = CompanyModuleSetup::query()->where('company_id', $this->company->id)->where('module_key', $moduleKey)->first();
        $roles = $this->roles();
        $capabilities = $this->capabilities($moduleKey);
        $suggestion = $this->suggestion($capabilities);

        return [
            'module' => ['key' => $moduleKey, 'name' => $manifest->name, 'requires' => array_map(fn ($k) => ['key' => $k, 'active' => ModuleGate::isActive($this->company, $k)], $manifest->requires)],
            'status' => $this->status($moduleKey),
            'revision' => $row?->revision ?? 0,
            'steps' => $contract['steps'],
            'completed_steps' => $row?->completed_steps ?? [],
            'capabilities' => array_values(array_map(fn ($c) => array_diff_key($c, ['permission_set' => 1]), $capabilities)),
            'roles' => $roles->map(fn (CompanyRole $r) => [
                'id' => $r->id, 'name' => $r->name, 'is_administrative' => (bool) $r->is_administrative,
                'editable' => $r->key !== 'owner',
                'holds' => collect($capabilities)->mapWithKeys(fn ($c) => [$c['key'] => $this->holding($r, $c)])->all(),
            ])->values()->all(),
            'suggestion' => $suggestion,
            // Always computed (a transient, never saved row for modules activated before ADR-803).
            'readiness' => $this->readiness($moduleKey, $row ?? new CompanyModuleSetup(['company_id' => $this->company->id, 'module_key' => $moduleKey, 'completed_steps' => []])),
            'fingerprint' => $this->fingerprint($moduleKey),
            'can' => [
                'configure_access' => $actor->hasCompanyPermission($this->company, 'roles.manage'),
                'configure_settings' => true, // each settings step keeps its own server guard (e.g. workforce.manage)
            ],
        ];
    }

    // ── access : preview then apply ──────────────────────────────────────────────────────────────

    /**
     * @param  list<array{role_id:int, capability:string, grant:bool}>  $changes  EXPLICIT cell changes only
     */
    public function preview(string $moduleKey, array $changes, User $actor): array
    {
        self::contract($moduleKey) ?? abort(404);
        $capabilities = collect($this->capabilities($moduleKey))->keyBy('key');
        $roles = $this->roles()->keyBy('id');
        $isOwner = $actor->isOwnerOf($this->company);
        $plan = [];
        $byRole = [];

        foreach ($changes as $i => $c) {
            $role = $roles->get((int) ($c['role_id'] ?? 0));
            $cap = $capabilities->get((string) ($c['capability'] ?? ''));
            if (! $role || ! $cap) {
                throw ValidationException::withMessages(["changes.$i" => 'Unknown role or capability for this company and module.']);
            }
            $byRole[$role->id][] = ['cap' => $cap, 'grant' => (bool) ($c['grant'] ?? false)];
        }

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
                    // Remove only what another capability still fully held requires — except capabilities CONTAINED in
                    // the revoked one (they were implied by it). The preview shows the exact removals.
                    $keep = collect($this->capabilities(null))->reject(fn ($o) => $o['key'] === $it['cap']['key'])
                        ->reject(fn ($o) => ! array_diff($o['permission_set'], $it['cap']['permission_set']))
                        ->filter(fn ($o) => ! array_diff($o['permission_set'], $after))->flatMap(fn ($o) => $o['permission_set'])->all();
                    $after = array_values(array_diff($after, array_diff($it['cap']['permission_set'], $keep)));
                }
            }
            $adds = array_values(array_diff($after, $current));
            $removes = array_values(array_diff($current, $after));
            $blocked = null;
            if ($role->key === 'owner') {
                $blocked = 'owner_role';
            } elseif (! $role->is_administrative && CompanyPermission::whereIn('key', $adds)->where('is_admin', true)->exists()) {
                $blocked = 'operational_role';
            } elseif (! $isOwner && $adds && collect($adds)->contains(fn ($k) => ! $actor->hasCompanyPermission($this->company, $k))) {
                $blocked = 'beyond_your_rights';
            }
            $plan[] = ['role_id' => $role->id, 'role' => $role->name, 'capabilities' => array_map(fn ($it) => ['key' => $it['cap']['key'], 'grant' => $it['grant']], $items),
                'adds' => count($adds), 'removes' => count($removes), 'add_keys' => $adds, 'remove_keys' => $removes, 'blocked' => $blocked];
        }

        return ['plan' => $plan, 'fingerprint' => $this->fingerprint($moduleKey), 'blocked' => collect($plan)->whereNotNull('blocked')->isNotEmpty()];
    }

    public function applyAccess(string $moduleKey, array $changes, string $fingerprint, User $actor): array
    {
        return DB::transaction(function () use ($moduleKey, $changes, $fingerprint, $actor) {
            $row = $this->row($moduleKey);
            CompanyModuleSetup::query()->whereKey($row->id)->lockForUpdate()->first();
            if (! hash_equals($this->fingerprint($moduleKey), $fingerprint)) {
                abort(response()->json(['message' => 'Access changed since the preview.', 'code' => 'stale_preview'], 409));
            }
            $preview = $this->preview($moduleKey, $changes, $actor);
            if ($preview['blocked']) {
                throw ValidationException::withMessages(['changes' => 'Some changes are not allowed.']);
            }
            $roles = $this->roles()->keyBy('id');
            foreach ($preview['plan'] as $p) {
                if (! $p['adds'] && ! $p['removes']) {
                    continue; // idempotent
                }
                $role = $roles->get($p['role_id']);
                $keys = array_values(array_diff(array_unique(array_merge($role->permissions->pluck('key')->all(), $p['add_keys'])), $p['remove_keys']));
                $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $keys)->pluck('id')->all());
            }
            $this->completeStep($row, 'access');
            app(AuditLogger::class)->logCompany($this->company->id, 'module.access_configured', 'module', $moduleKey, [
                'actorId' => $actor->id,
                'metadata' => ['changes' => collect($preview['plan'])->map(fn ($p) => array_intersect_key($p, array_flip(['role_id', 'capabilities', 'add_keys', 'remove_keys'])))->all()],
            ]);

            return $this->describe($moduleKey, $actor);
        });
    }

    // ── steps & confirmation ─────────────────────────────────────────────────────────────────────

    public function markStep(string $moduleKey, string $step, User $actor): array
    {
        $contract = self::contract($moduleKey) ?? abort(404);
        if ($step === 'access' || ! in_array($step, $contract['steps'], true)) {
            throw ValidationException::withMessages(['step' => 'Unknown step.']); // access is completed by applyAccess only
        }
        DB::transaction(fn () => $this->completeStep($this->row($moduleKey), $step));

        return $this->describe($moduleKey, $actor);
    }

    public function confirm(string $moduleKey, int $revision, User $actor): array
    {
        return DB::transaction(function () use ($moduleKey, $revision, $actor) {
            self::contract($moduleKey) ?? abort(404);
            abort_unless(ModuleGate::isActive($this->company, $moduleKey), 422, 'Module not active.');
            $row = CompanyModuleSetup::query()->where('company_id', $this->company->id)->where('module_key', $moduleKey)->lockForUpdate()->first() ?? $this->row($moduleKey);
            if ($row->status === CompanyModuleSetup::READY && $row->revision === $revision) {
                return $this->describe($moduleKey, $actor); // double confirmation : idempotent, no second audit
            }
            if ($row->revision !== $revision) {
                abort(response()->json(['message' => 'Configuration changed meanwhile.', 'code' => 'stale_revision'], 409));
            }
            $missing = collect($this->readiness($moduleKey, $row))->where('ok', false)->pluck('key')->all();
            if ($missing) {
                abort(response()->json(['message' => 'Prerequisites missing.', 'code' => 'not_ready', 'missing' => $missing], 422));
            }
            $row->forceFill(['status' => CompanyModuleSetup::READY, 'configured_at' => now(), 'configured_by' => $actor->id, 'revision' => $row->revision + 1])->save();
            app(AuditLogger::class)->logCompany($this->company->id, 'module.configured', 'module', $moduleKey, ['actorId' => $actor->id, 'metadata' => ['steps' => $row->completed_steps]]);

            return $this->describe($moduleKey, $actor);
        });
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────────

    private function completeStep(CompanyModuleSetup $row, string $step): void
    {
        $steps = array_values(array_unique(array_merge($row->completed_steps ?? [], [$step])));
        $row->forceFill([
            'completed_steps' => $steps,
            // a change after « ready » sends the module back to in_progress until confirmed again
            'status' => CompanyModuleSetup::IN_PROGRESS,
            'revision' => $row->revision + 1,
        ])->save();
    }

    private function readiness(string $moduleKey, CompanyModuleSetup $row): array
    {
        $class = self::contract($moduleKey)['readiness'] ?? null;

        return $class ? app($class)->checks($this->company, $row) : [];
    }

    private function roles()
    {
        return CompanyRole::query()->where('company_id', $this->company->id)->with('permissions:id,key')->orderBy('name')->get();
    }

    /** Capabilities of a module (null = all company modules, used to protect permissions shared by bundles). */
    private function capabilities(?string $moduleKey): array
    {
        $modules = $moduleKey ? [$moduleKey => ModuleRegistry::definitions()[$moduleKey]] : ModuleRegistry::forScope('company');
        $out = [];
        foreach ($modules as $m) {
            foreach ($m->bundles as $b) {
                $out[] = ['key' => $b['key'], 'label' => $b['label'] ?? $b['key'], 'hint' => $b['hint'] ?? null,
                    'sensitive' => (bool) (($b['sensitive'] ?? false) || ($b['is_admin'] ?? false)),
                    'administrative_only' => (bool) ($b['is_admin'] ?? false),
                    'scope' => $b['scope'] ?? 'collective',
                    'permission_count' => count($b['permissions'] ?? []),
                    'permission_set' => array_values($b['permissions'] ?? [])];
            }
        }

        return $out;
    }

    private function holding(CompanyRole $role, array $cap): string
    {
        $held = array_intersect($cap['permission_set'], $role->permissions->pluck('key')->all());

        return count($held) === count($cap['permission_set']) ? 'full' : (count($held) ? 'partial' : 'none');
    }

    /** Suggestions from the RBAC preset recommended for the company's JobDomain : company role key ↔ preset role key. */
    private function suggestion(array $capabilities): array
    {
        $rec = collect(PresetService::recommendationsFor((string) $this->company->jobdomain_key, $this->company->market_key))
            ->firstWhere(fn ($r) => $r['kind'] === 'rbac' && $r['usable']);
        if (! $rec) {
            return ['available' => false, 'label' => null, 'roles' => []];
        }
        $content = PresetService::content(PresetService::find('rbac', $rec['effective_id']));
        $mine = array_column($capabilities, 'key');
        $byKey = $this->roles()->keyBy('key');
        $roles = [];
        foreach ($content['roles'] ?? [] as $pr) {
            if ($role = $byKey->get($pr['key'] ?? '')) {
                $roles[$role->id] = array_values(array_intersect($pr['bundles'] ?? [], $mine));
            }
        }

        return ['available' => true, 'label' => $rec['label'], 'roles' => $roles];
    }

    /** Concurrency fingerprint : the module's permission set on every role of the company. */
    private function fingerprint(string $moduleKey): string
    {
        $set = collect($this->capabilities($moduleKey))->flatMap(fn ($c) => $c['permission_set'])->unique()->all();

        return sha1(json_encode($this->roles()->mapWithKeys(fn ($r) => [$r->id => $r->permissions->pluck('key')->intersect($set)->sort()->values()->all()])->all()));
    }
}
