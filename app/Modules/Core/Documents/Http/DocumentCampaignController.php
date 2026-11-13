<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\ReadModels\DocumentCampaignTrackingReadModel;
use App\Core\Documents\Services\CampaignTargetingResolver;
use App\Core\Documents\Services\DocumentCampaignService;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Jobs\Documents\LaunchDocumentCampaignJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-761 (DMS M4) — pilotage des campagnes documentaires. Gated `documents.manage` (le pilotage
 * n'implique PAS le droit de télécharger les pièces sensibles — ce dernier reste gouverné par
 * DocumentAccessPolicy au niveau de chaque document).
 */
class DocumentCampaignController extends Controller
{
    use PublishesRealtimeEvents;

    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $campaigns = DocumentCampaign::where('company_id', $company->id)
            ->with('documentType:id,code,label')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status,
                'document_type' => $c->documentType?->code, 'document_label' => $c->documentType?->label,
                'recipients_total' => $c->recipients_total, 'due_date' => $c->due_date?->toDateString(),
                'launched_at' => $c->launched_at?->toIso8601String(),
            ]);

        // Types documentaires personnels activés (catalogue — pas de liste codée en dur).
        $typeIds = \App\Core\Documents\DocumentTypeActivation::where('company_id', $company->id)->where('enabled', true)->pluck('document_type_id');
        $types = \App\Core\Documents\DocumentType::whereIn('id', $typeIds)
            ->where('scope', \App\Core\Documents\DocumentType::SCOPE_COMPANY_USER)
            ->orderBy('label')
            ->get(['id', 'code', 'label']);

        return response()->json([
            'campaigns' => $campaigns,
            'departments' => CampaignTargetingResolver::departments($company),
            'document_types' => $types,
        ]);
    }

    public function preview(Request $request, DocumentCampaignService $service): JsonResponse
    {
        $validated = $request->validate($this->targetingRules());
        $company = $request->attributes->get('company');

        return response()->json($service->preview($company, $validated['targeting']));
    }

    public function store(Request $request, DocumentCampaignService $service): JsonResponse
    {
        $validated = $request->validate($this->targetingRules() + [
            'document_type_code' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date', 'after:today'],
            'requires_validation' => ['nullable', 'boolean'],
        ]);
        $company = $request->attributes->get('company');

        $campaign = $service->create($company, $request->user(), $validated['document_type_code'], $validated['targeting'], [
            'name' => $validated['name'] ?? null,
            'message' => $validated['message'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'requires_validation' => $validated['requires_validation'] ?? true,
        ]);

        // Lancement ASYNCHRONE fiable (idempotent + verrouillé côté service).
        LaunchDocumentCampaignJob::dispatch($campaign->id, (int) $company->id);

        $this->publishDomainEvent('document.updated', $company->id, ['type' => 'campaign_launched', 'entity' => 'DocumentCampaign', 'campaign_id' => $campaign->id]);

        return response()->json(['campaign' => ['id' => $campaign->id, 'status' => $campaign->status, 'recipients_total' => $campaign->recipients_total]], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $campaign = $this->find($request, $id);

        return response()->json(DocumentCampaignTrackingReadModel::summary($campaign));
    }

    public function recipients(Request $request, int $id): JsonResponse
    {
        $campaign = $this->find($request, $id);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        return response()->json(DocumentCampaignTrackingReadModel::recipients($campaign, $page, $perPage));
    }

    public function launch(Request $request, int $id, DocumentCampaignService $service): JsonResponse
    {
        $campaign = $this->find($request, $id);
        $stats = $service->launch($campaign, $request->user());

        return response()->json(['message' => 'Campaign launch processed.', 'stats' => $stats]);
    }

    public function cancel(Request $request, int $id, DocumentCampaignService $service): JsonResponse
    {
        $campaign = $this->find($request, $id);
        $service->cancel($campaign, $request->user());

        return response()->json(['message' => 'Campaign cancelled.']);
    }

    /**
     * ADR-770 — targeting rules. Every key the resolver reads MUST be declared here : with a nested
     * rule (`targeting.mode`), Laravel returns ONLY the validated nested keys, so an undeclared
     * `department_ids` / `employee_ids` / `person_refs` was silently dropped and a department or
     * person campaign targeted nobody (0 recipients) through the real HTTP path.
     */
    private function targetingRules(): array
    {
        return [
            'targeting' => ['required', 'array'],
            'targeting.mode' => ['required', 'string', 'in:all,department,employees,persons,single'],
            'targeting.department_ids' => ['required_if:targeting.mode,department', 'array', 'min:1'],
            'targeting.department_ids.*' => ['integer'],
            'targeting.employee_ids' => ['required_if:targeting.mode,employees', 'array', 'min:1'],
            'targeting.employee_ids.*' => ['integer'],
            'targeting.person_refs' => ['sometimes', 'array'],
            'targeting.person_refs.*' => ['string', 'regex:/^(user|employee)-[0-9]+$/'],
            'targeting.person_ref' => ['sometimes', 'string', 'regex:/^(user|employee)-[0-9]+$/'],
            'targeting.include_terminated' => ['sometimes', 'boolean'],
            'targeting.filters' => ['sometimes', 'array'],
            'targeting.filters.status' => ['sometimes', 'array'],
            'targeting.filters.status.*' => ['string'],
        ];
    }

    private function find(Request $request, int $id): DocumentCampaign
    {
        $company = $request->attributes->get('company');

        return DocumentCampaign::where('company_id', $company->id)->findOrFail($id);
    }
}
