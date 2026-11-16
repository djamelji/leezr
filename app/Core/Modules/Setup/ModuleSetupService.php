<?php

namespace App\Core\Modules\Setup;

use App\Company\RBAC\CapabilityAccessService;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Presets\PresetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-803 / 803.1 — guided module configuration : « who can do what » → module settings → confirmation.
 *
 * Access changes are delegated to the RBAC engine (CapabilityAccessService + MembershipSecurityPolicy) —
 * exactly the behaviour of Équipe → Rôles. This service only adds : steps, suggestions, readiness and a
 * trustworthy « ready » state. Activation stays ModuleActivationEngine's job ; nothing is bought or billed.
 *
 * « ready » is never taken from the stored row alone : at read time it is re-verified (prerequisites, access
 * fingerprint vs confirmation, module-specific stale reasons). A stale confirmation is shown as `needs_review`.
 */
final class ModuleSetupService
{
    private CapabilityAccessService $access;

    public function __construct(private Company $company)
    {
        $this->access = new CapabilityAccessService($company);
    }

    public static function contract(string $moduleKey): ?array
    {
        $m = ModuleRegistry::definitions()[$moduleKey] ?? null;

        return $m && ! empty($m->setup['steps']) ? $m->setup : null;
    }

    private function find(string $moduleKey): ?CompanyModuleSetup
    {
        return CompanyModuleSetup::query()->where('company_id', $this->company->id)->where('module_key', $moduleKey)->first();
    }

    /** available | to_configure | in_progress | ready | needs_review | suspended | legacy (activated before ADR-803). */
    public function status(string $moduleKey): ?string
    {
        if (! self::contract($moduleKey)) {
            return null;
        }
        $row = $this->find($moduleKey);
        if (! ModuleGate::isActive($this->company, $moduleKey)) {
            return $row ? 'suspended' : 'available';
        }
        if (! $row) {
            return 'legacy';
        }

        return $row->status === CompanyModuleSetup::READY && $this->staleness($moduleKey, $row) ? 'needs_review' : $row->status;
    }

    /** Why a stored « ready » is no longer trustworthy (empty = still valid). */
    private function staleness(string $moduleKey, CompanyModuleSetup $row): array
    {
        $reasons = collect($this->readiness($moduleKey, $row))->where('ok', false)->where('required', true)->pluck('key')->map(fn ($k) => "missing:$k")->all();
        if ($row->access_fingerprint && ! hash_equals($row->access_fingerprint, $this->access->fingerprint($moduleKey))) {
            $reasons[] = 'access_changed';
        }
        // ADR-804 — EFFECTIVE settings compared (no timestamp heuristic : a write without change is not a change).
        $current = $this->settingsFingerprint($moduleKey);
        if ($row->settings_fingerprint && $current && ! hash_equals($row->settings_fingerprint, $current)) {
            $reasons[] = 'settings_changed';
        }

        return array_values($reasons);
    }

    private function settingsFingerprint(string $moduleKey): ?string
    {
        $class = self::contract($moduleKey)['readiness'] ?? null;

        return $class ? app($class)->settingsFingerprint($this->company) : null;
    }

    public function row(string $moduleKey): CompanyModuleSetup
    {
        return CompanyModuleSetup::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'module_key' => $moduleKey],
            ['status' => CompanyModuleSetup::TO_CONFIGURE, 'completed_steps' => []],
        );
    }

    public function describe(string $moduleKey, User $actor): array
    {
        $contract = self::contract($moduleKey) ?? abort(404);
        $manifest = ModuleRegistry::definitions()[$moduleKey];
        $row = $this->find($moduleKey);
        $access = $this->access->describe($moduleKey, $actor);
        $transient = $row ?? new CompanyModuleSetup(['company_id' => $this->company->id, 'module_key' => $moduleKey, 'completed_steps' => []]);

        return [
            'module' => ['key' => $moduleKey, 'name' => $manifest->name, 'requires' => array_map(fn ($k) => ['key' => $k, 'name' => ModuleRegistry::definitions()[$k]->name ?? $k,
                'active' => ModuleGate::isActive($this->company, $k), 'setup_status' => $this->status($k)], $manifest->requires)],
            'status' => $this->status($moduleKey),
            'stale_reasons' => $row && $row->status === CompanyModuleSetup::READY ? $this->staleness($moduleKey, $row) : [],
            'revision' => $row?->revision ?? 0,
            'steps' => $contract['steps'],
            'completed_steps' => $row?->completed_steps ?? [],
            'capabilities' => $access['capabilities'],
            'roles' => $access['roles'],
            'suggestion' => $this->suggestion($access['capabilities'], $access['roles']),
            'readiness' => $this->readiness($moduleKey, $transient),
            'related' => $this->related($contract['related'] ?? [], $access['roles']),
            'fingerprint' => $access['fingerprint'],
            'can' => ['configure_access' => $access['can_change'], 'configure_settings' => true],
        ];
    }

    public function preview(string $moduleKey, array $changes, User $actor): array
    {
        self::contract($moduleKey) ?? abort(404);

        return $this->access->preview($moduleKey, $changes, $actor);
    }

    public function applyAccess(string $moduleKey, array $changes, string $fingerprint, User $actor, bool $confirmAffected = false): array
    {
        self::contract($moduleKey) ?? abort(404);
        DB::transaction(function () use ($moduleKey, $changes, $fingerprint, $actor, $confirmAffected) {
            $row = $this->row($moduleKey);
            CompanyModuleSetup::query()->whereKey($row->id)->lockForUpdate()->first();
            $plan = $this->access->apply($moduleKey, $changes, $fingerprint, $actor, $confirmAffected, 'module_setup');
            $this->completeStep($row->fresh(), 'access');
            // ADR-808/808.1 : holders reviewed in the assistant ⇒ decided, within the actor's delegation rights.
            $confirmed = PendingModuleAccess::confirm($this->company, $actor, $moduleKey, null, 'module_setup');
            app(AuditLogger::class)->logCompany($this->company->id, 'module.access_configured', 'module', $moduleKey, [
                'actorId' => $actor->id, 'metadata' => ['roles' => count($plan), 'pending_confirmed' => count($confirmed['confirmed']), 'pending_refused' => count($confirmed['refused'])],
            ]);
        });

        return $this->describe($moduleKey, $actor);
    }

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
            $fresh = $row->status === CompanyModuleSetup::READY && ! $this->staleness($moduleKey, $row);
            if ($fresh && $row->revision === $revision) {
                return $this->describe($moduleKey, $actor); // double confirmation : idempotent, no second audit
            }
            if ($row->revision !== $revision) {
                abort(response()->json(['message' => 'Configuration changed meanwhile.', 'code' => 'stale_revision'], 409));
            }
            $missing = collect($this->readiness($moduleKey, $row))->where('ok', false)->where('required', true)->pluck('key')->all();
            if ($missing) {
                abort(response()->json(['message' => 'Prerequisites missing.', 'code' => 'not_ready', 'missing' => $missing], 422));
            }
            $row->forceFill(['status' => CompanyModuleSetup::READY, 'configured_at' => now(), 'configured_by' => $actor->id,
                'access_fingerprint' => $this->access->fingerprint($moduleKey), 'settings_fingerprint' => $this->settingsFingerprint($moduleKey),
                'revision' => $row->revision + 1])->save();
            app(AuditLogger::class)->logCompany($this->company->id, 'module.configured', 'module', $moduleKey, ['actorId' => $actor->id, 'metadata' => ['steps' => $row->completed_steps]]);

            return $this->describe($moduleKey, $actor);
        });
    }

    /** Re-activation : choices and progress are kept, but « ready » must be confirmed again. */
    public function onReactivated(string $moduleKey): void
    {
        CompanyModuleSetup::query()->where('company_id', $this->company->id)->where('module_key', $moduleKey)
            ->where('status', CompanyModuleSetup::READY)->update(['status' => CompanyModuleSetup::IN_PROGRESS]);
    }

    private function completeStep(CompanyModuleSetup $row, string $step): void
    {
        $row->forceFill([
            'completed_steps' => array_values(array_unique(array_merge($row->completed_steps ?? [], [$step]))),
            'status' => CompanyModuleSetup::IN_PROGRESS, // a change after « ready » is confirmed again
            'revision' => $row->revision + 1,
        ])->save();
    }

    private function readiness(string $moduleKey, CompanyModuleSetup $row): array
    {
        $class = self::contract($moduleKey)['readiness'] ?? null;

        // `required` defaults to true ; non-required checks are recommendations (never blocking).
        return $class ? array_map(fn ($c) => $c + ['required' => true], app($class)->checks($this->company, $row)) : [];
    }

    /**
     * ADR-805 — capabilities of OTHER modules that decide part of this module's access (e.g. who sees everyone's
     * documents, the pay inside them). Shown read-only with their holding per role and the owning module :
     * they are changed where they belong (Équipe → Rôles), never duplicated in this assistant.
     */
    private function related(array $keys, array $roles): array
    {
        if (! $keys) {
            return [];
        }
        $byKey = collect($this->access->capabilities(null))->keyBy('key');
        $perms = CapabilityAccessService::rolePermissionKeys($this->company);

        return collect($keys)->filter(fn ($k) => $byKey->has($k))->map(function ($k) use ($byKey, $roles, $perms) {
            $c = $byKey[$k];

            return ['key' => $k, 'label' => $c['label'], 'module' => $c['module'], 'module_name' => ModuleRegistry::definitions()[$c['module']]->name ?? $c['module'],
                'module_active' => ModuleGate::isActive($this->company, $c['module']), 'sensitive' => $c['sensitive'],
                'holders' => collect($roles)->map(fn ($r) => ['role_id' => $r['id'], 'role' => $r['name'], 'holds' => CapabilityAccessService::holding($perms[$r['id']] ?? [], $c)])
                    ->where('holds', '!=', 'none')->values()->all()];
        })->values()->all();
    }

    /**
     * ADR-805 — what still runs / is still relied upon if `$moduleKey` is deactivated, including the active
     * modules that require it (they go with it). Read-only : never blocks, never changes the deactivation.
     */
    public function deactivationImpact(string $moduleKey): array
    {
        $defs = ModuleRegistry::definitions();
        abort_unless(isset($defs[$moduleKey]), 404);
        $keys = [$moduleKey];
        for ($i = 0; $i < count($keys); $i++) { // active dependents, transitively
            foreach ($defs as $k => $m) {
                if (! in_array($k, $keys, true) && in_array($keys[$i], $m->requires, true) && ModuleGate::isActive($this->company, $k)) {
                    $keys[] = $k;
                }
            }
        }
        $out = [];
        foreach ($keys as $k) {
            $class = self::contract($k)['readiness'] ?? null;
            $impl = $class ? app($class) : null;
            foreach ($impl instanceof ModuleDeactivationImpact ? $impl->deactivationImpact($this->company) : [] as $item) {
                $out[] = $item + ['module' => $k, 'module_name' => $defs[$k]->name];
            }
        }

        return ['modules' => $keys, 'items' => $out];
    }

    /** Suggestions from the RBAC preset recommended for the company's JobDomain (company role key ↔ preset role key). */
    private function suggestion(array $capabilities, array $roles): array
    {
        $rec = collect(PresetService::recommendationsFor((string) $this->company->jobdomain_key, $this->company->market_key))
            ->first(fn ($r) => $r['kind'] === 'rbac' && $r['usable']);
        if (! $rec) {
            return ['available' => false, 'label' => null, 'roles' => []];
        }
        $content = PresetService::content(PresetService::find('rbac', $rec['effective_id']));
        $mine = array_column($capabilities, 'key');
        $byKey = collect($roles)->keyBy('key');
        $out = [];
        foreach ($content['roles'] ?? [] as $pr) {
            if ($role = $byKey->get($pr['key'] ?? '')) {
                $out[$role['id']] = array_values(array_intersect($pr['bundles'] ?? [], $mine));
            }
        }

        return ['available' => true, 'label' => $rec['label'], 'roles' => $out];
    }
}
