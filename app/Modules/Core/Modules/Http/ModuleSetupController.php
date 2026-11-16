<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Modules\Setup\ModuleSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-803 — guided module configuration (Company). Validation only ; every rule lives in ModuleSetupService.
 * Routes : modules.manage for the assistant ; roles.manage in addition to WRITE access (RBAC stays separate).
 */
class ModuleSetupController
{
    private function svc(Request $request): ModuleSetupService
    {
        return new ModuleSetupService($request->attributes->get('company'));
    }

    public function show(Request $request, string $key): JsonResponse
    {
        return response()->json($this->svc($request)->describe($key, $request->user()));
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        return response()->json($this->svc($request)->preview($key, $this->changes($request), $request->user()));
    }

    public function applyAccess(Request $request, string $key): JsonResponse
    {
        $fp = $request->validate(['fingerprint' => 'required|string|size:40'])['fingerprint'];

        return response()->json($this->svc($request)->applyAccess($key, $this->changes($request), $fp, $request->user()));
    }

    public function step(Request $request, string $key): JsonResponse
    {
        return response()->json($this->svc($request)->markStep($key, $request->validate(['step' => 'required|string|max:40'])['step'], $request->user()));
    }

    public function confirm(Request $request, string $key): JsonResponse
    {
        return response()->json($this->svc($request)->confirm($key, (int) $request->validate(['revision' => 'required|integer|min:0'])['revision'], $request->user()));
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
