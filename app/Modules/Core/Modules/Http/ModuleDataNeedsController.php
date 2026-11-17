<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Modules\Data\ModuleDataNeeds;
use App\Core\Modules\ModuleGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** ADR-820 — module data needs ↔ company fields (assistant step « Données »). modules.manage. */
class ModuleDataNeedsController extends Controller
{
    public function show(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_if(ModuleDataNeeds::declared($key) === [], 404);

        return response()->json(['needs' => (new ModuleDataNeeds($company))->describe($key), 'module_active' => ModuleGate::isActive($company, $key)]);
    }

    public function bind(Request $request, string $key, string $need): JsonResponse
    {
        $data = $request->validate(['field_definition_id' => 'required|integer']);

        return response()->json(['needs' => (new ModuleDataNeeds($request->attributes->get('company')))->bind($key, $need, (int) $data['field_definition_id'], $request->user())]);
    }

    public function createField(Request $request, string $key, string $need): JsonResponse
    {
        $data = $request->validate(['label' => 'required|string|min:2|max:100']);

        return response()->json(['needs' => (new ModuleDataNeeds($request->attributes->get('company')))->createAndBind($key, $need, $data['label'], $request->user())], 201);
    }
}
