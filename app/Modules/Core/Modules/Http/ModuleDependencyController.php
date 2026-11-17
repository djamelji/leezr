<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Modules\ModuleDependencyResolver;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** ADR-821 — what an activation will do (read only, before the company confirms) ; deactivation : ModuleDeactivationPreviewController. */
class ModuleDependencyController extends Controller
{
    public function activation(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(ModuleRegistry::definitions()[$key]), 404);

        return response()->json(ModuleDependencyResolver::forCompany($request->attributes->get('company'), $key));
    }
}
