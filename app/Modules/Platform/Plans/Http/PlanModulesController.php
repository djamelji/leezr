<?php

namespace App\Modules\Platform\Plans\Http;

use App\Core\Modules\ModuleDependencyResolver;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * ADR-821 — the modules of an offer, READ from the modules (min plan, add-on price) — the plan holds no module
 * list. Each module : included / option / not included, its dependencies and whether the offer covers them.
 * Edited where it lives (the module page) ; a change creating a coverage gap is refused there.
 */
class PlanModulesController extends Controller
{
    public function show(string $key): JsonResponse
    {
        abort_unless(in_array($key, PlanRegistry::keys(), true), 404);
        $rows = [];
        foreach (ModuleRegistry::forScope('company') as $m) {
            if (str_starts_with($m->key, 'core.')) {
                continue;
            }
            $status = ModuleDependencyResolver::commercialStatus($m->key, $key);
            $deps = array_map(fn ($d) => ['key' => $d, 'name' => ModuleRegistry::definitions()[$d]->name ?? $d,
                'status' => ModuleDependencyResolver::commercialStatus($d, $key)], ModuleDependencyResolver::requiresOf($m->key));
            $gaps = $status === 'not_included' ? [] : array_values(array_filter($deps, fn ($d) => $status === 'included' ? $d['status'] !== 'included' : $d['status'] === 'not_included'));
            $rows[] = ['key' => $m->key, 'name' => $m->name, 'status' => $status, 'dependencies' => $deps, 'gaps' => $gaps];
        }

        return response()->json(['plan' => $key, 'modules' => $rows, 'coherent' => collect($rows)->every(fn ($r) => $r['gaps'] === [])]);
    }
}
