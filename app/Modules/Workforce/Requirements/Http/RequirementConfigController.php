<?php

namespace App\Modules\Workforce\Requirements\Http;

use App\Modules\Workforce\Requirements\RequirementConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-792 (L2) — « Informations & documents demandés » : configuration par poste, en MODE PARALLÈLE
 * (registre L1 seulement ; aucun effet opérationnel). Lecture : settings.manage OU documents.configure
 * (route). Écriture : informations → settings.manage, documents → documents.configure (contrôlé ici,
 * élément par élément).
 */
class RequirementConfigController extends Controller
{
    private function service(Request $request): RequirementConfigService
    {
        return new RequirementConfigService($request->attributes->get('company'), $request->user());
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
        $request->validate(['scope' => 'sometimes|string|max:40']);
        $service = $this->service($request);
        $scope = $request->query('scope', 'all');

        return response()->json([
            'parallel_mode' => true,
            'scope' => $scope,
            'scopes' => $service->scopes(),
            'state' => $service->state($scope),
            'can' => $this->can($request),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        return response()->json($this->service($request)->catalog());
    }

    public function preview(Request $request): JsonResponse
    {
        [$scope, $items] = $this->validated($request);

        return response()->json($this->service($request)->preview($scope, $items));
    }

    public function save(Request $request): JsonResponse
    {
        [$scope, $items] = $this->validated($request);

        return response()->json($this->service($request)->commit($scope, $items));
    }

    public function legacy(Request $request): JsonResponse
    {
        return response()->json(['items' => $this->service($request)->legacyMapping()]);
    }

    /**
     * ADR-817 (L1) — READ-ONLY takeover proposal of the historical role rules for one position.
     * Adoption goes through `save` (explicit selection), never automatically.
     */
    public function proposal(Request $request): JsonResponse
    {
        $request->validate(['scope' => 'required|string|max:40']);
        $job = $this->service($request)->jobRoleOf((string) $request->query('scope'));
        abort_if($job === null, 422, __('requirements.position_required'));

        return response()->json((new \App\Modules\Workforce\Requirements\PositionRequirementProposal($request->attributes->get('company')))->for($job));
    }

    /** @return array{0: string, 1: array} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'scope' => 'required|string|max:40',
            'items' => 'required|array|min:1|max:200',
            'items.*.kind' => 'required|in:field,document',
            'items.*.code' => 'required|string|max:100',
            'items.*.state' => 'required|in:required,optional,removed',
            'items.*.stage' => 'nullable|in:hire,onboarding,ongoing', // ADR-819 : moment de collecte
        ]);
        $can = $this->can($request);
        foreach ($data['items'] as $item) {
            abort_unless($can[$item['kind'] === 'field' ? 'fields' : 'documents'], 403, __('requirements.forbidden_section'));
        }

        return [$data['scope'], $data['items']];
    }
}
