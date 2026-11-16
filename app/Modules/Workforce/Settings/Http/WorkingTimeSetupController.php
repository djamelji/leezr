<?php

namespace App\Modules\Workforce\Settings\Http;

use App\Modules\Workforce\Settings\WorkingTimeSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** ADR-799 — « Temps de travail » step of the Workforce assistant (view : workforce.view ; change : workforce.manage). */
class WorkingTimeSetupController extends Controller
{
    private function service(Request $request): WorkingTimeSetupService
    {
        return new WorkingTimeSetupService($request->attributes->get('company'));
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service($request)->describe());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => 'required|array|min:1|max:30']);

        return response()->json($this->service($request)->save($data['values'], $request->user()?->id));
    }

    public function confirmConvention(Request $request): JsonResponse
    {
        $data = $request->validate(['convention_collective_id' => 'present|nullable|integer']);

        return response()->json($this->service($request)->confirmConvention($data['convention_collective_id'], $request->user()?->id));
    }
}
