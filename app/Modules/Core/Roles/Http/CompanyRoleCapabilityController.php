<?php

namespace App\Modules\Core\Roles\Http;

use App\Company\RBAC\CapabilityAccessService;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-803.1 — Équipe → Rôles « par capacité » : the SAME engine and policy as the module assistant
 * (CapabilityAccessService + MembershipSecurityPolicy). roles.view to read, roles.manage to change.
 */
class CompanyRoleCapabilityController
{
    public function modules(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $mods = collect(ModuleRegistry::forScope('company'))
            ->filter(fn ($m, $k) => $m->bundles && ModuleGate::isActive($company, $k))
            ->map(fn ($m, $k) => ['key' => $k, 'name' => $m->name, 'capabilities' => count($m->bundles)])->values();

        return response()->json(['modules' => $mods]);
    }

    public function show(Request $request, string $module): JsonResponse
    {
        return response()->json($this->svc($request, $module)->describe($module, $request->user()));
    }

    public function preview(Request $request, string $module): JsonResponse
    {
        return response()->json($this->svc($request, $module)->preview($module, $this->changes($request), $request->user()));
    }

    public function apply(Request $request, string $module): JsonResponse
    {
        $v = $request->validate(['fingerprint' => 'required|string|size:40', 'confirm_affected' => 'sometimes|boolean']);
        $svc = $this->svc($request, $module);
        $plan = $svc->apply($module, $this->changes($request), $v['fingerprint'], $request->user(), (bool) ($v['confirm_affected'] ?? false), 'roles_editor');

        return response()->json(['plan' => $plan] + $svc->describe($module, $request->user()));
    }

    private function svc(Request $request, string $module): CapabilityAccessService
    {
        $company = $request->attributes->get('company');
        abort_unless(isset(ModuleRegistry::forScope('company')[$module]) && ModuleGate::isActive($company, $module), 404);

        return new CapabilityAccessService($company);
    }

    private function changes(Request $request): array
    {
        return $request->validate([
            'changes' => 'present|array|max:500',
            'changes.*.role_id' => 'required|integer',
            'changes.*.capability' => 'required|string|max:120',
            'changes.*.grant' => 'required|boolean',
        ])['changes'];
    }
}
