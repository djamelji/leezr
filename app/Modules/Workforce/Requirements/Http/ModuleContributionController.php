<?php

namespace App\Modules\Workforce\Requirements\Http;

use App\Core\Modules\Contributions\CompanyModuleContributions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-794 (L4) — module contributions inside « Informations & documents demandés » (ADR-792) : what each
 * module brings, and the company's explicit adoption of its proposals. PARALLEL MODE (L1 registry only).
 * Reading : settings.manage OR documents.configure (route) ; deciding : fields → settings.manage,
 * documents → documents.configure (checked per proposal by the service).
 */
class ModuleContributionController extends Controller
{
    private function service(Request $request): CompanyModuleContributions
    {
        return new CompanyModuleContributions($request->attributes->get('company'), $request->user());
    }

    private function can(Request $request): array
    {
        $company = $request->attributes->get('company');

        return [
            'fields' => $request->user()->hasCompanyPermission($company, 'settings.manage'),
            'documents' => $request->user()->hasCompanyPermission($company, 'documents.configure'),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['parallel_mode' => true, 'modules' => $this->service($request)->overview(), 'can' => $this->can($request)]);
    }

    public function preview(Request $request, string $module): JsonResponse
    {
        return response()->json($this->service($request)->preview($module, $this->decisions($request), $this->can($request)));
    }

    public function decide(Request $request, string $module): JsonResponse
    {
        return response()->json($this->service($request)->commit($module, $this->decisions($request), $this->can($request)));
    }

    private function decisions(Request $request): array
    {
        return $request->validate([
            'decisions' => 'required|array|min:1|max:100',
            'decisions.*.ref' => 'required|string|max:191',
            'decisions.*.decision' => 'required|in:adopt,decline,keep,replace',
        ])['decisions'];
    }
}
