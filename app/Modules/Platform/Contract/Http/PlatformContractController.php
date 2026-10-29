<?php

namespace App\Modules\Platform\Contract\Http;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\Services\DocumentPdfGenerator;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Contract\Services\ContractTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W-CONTRACT-3B (ADR-650) — administration PLATFORM du catalogue de modèles de contrat.
 *
 * Réutilise `DocumentTemplate` + `ContractTemplateService` (aucun moteur parallèle). Gouvernance :
 * la publication TECHNIQUE est distincte de la VALIDATION JURIDIQUE (deux actions, deux champs) —
 * une publication n'accorde jamais « validé juridiquement ». Gated par `manage_jobdomains`
 * (permission Platform réelle, pas de superadmin implicite). N'expose pas les contrats des Companies.
 */
class PlatformContractController extends Controller
{
    private function actorId(Request $request): int
    {
        return $request->user()?->id ?? 0;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => ContractTemplateService::platformList([
            'market_key' => $request->query('market_key'),
            'jobdomain_key' => $request->query('jobdomain_key'),
            'contract_type' => $request->query('contract_type'),
            'status' => $request->query('status'),
        ])]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $t = DocumentTemplate::whereNull('company_id')->where('subject_type', 'contract')->findOrFail($id);

        return response()->json(array_merge(ContractTemplateService::present($t), [
            'body_html' => $t->body_html, 'header_html' => $t->header_html, 'footer_html' => $t->footer_html,
        ]));
    }

    public function variables(): JsonResponse
    {
        return response()->json(['data' => DocumentVariableResolver::availableVariables('contract')]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contract_type' => 'nullable|string|max:50',
            'market_key' => 'nullable|string|max:10',
            'jobdomain_key' => 'nullable|string|max:50',
            'body_html' => 'nullable|string',
            'effective_date' => 'nullable|date',
            'jurisdiction' => 'nullable|string|max:100',
        ]);

        return response()->json(ContractTemplateService::present(
            ContractTemplateService::platformCreate($validated, $this->actorId($request))
        ), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'body_html' => 'sometimes|string',
            'header_html' => 'sometimes|nullable|string',
            'footer_html' => 'sometimes|nullable|string',
            'contract_type' => 'sometimes|nullable|string|max:50',
            'market_key' => 'sometimes|nullable|string|max:10',
            'jobdomain_key' => 'sometimes|nullable|string|max:50',
            'effective_date' => 'sometimes|nullable|date',
            'jurisdiction' => 'sometimes|nullable|string|max:100',
        ]);

        try {
            $t = ContractTemplateService::platformUpdate($id, $validated, $this->actorId($request));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(ContractTemplateService::present($t));
    }

    public function version(Request $request, int $id): JsonResponse
    {
        return response()->json(ContractTemplateService::present(
            ContractTemplateService::platformVersion($id, $this->actorId($request))
        ), 201);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        return response()->json(ContractTemplateService::present(
            ContractTemplateService::platformPublish($id, $this->actorId($request))
        ));
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return response()->json(ContractTemplateService::present(
            ContractTemplateService::platformArchive($id, $this->actorId($request))
        ));
    }

    /** Validation JURIDIQUE — décision distincte de la publication technique. */
    public function legalStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['legal_status' => 'required|string']);

        try {
            $t = ContractTemplateService::platformSetLegalStatus($id, $validated['legal_status'], $this->actorId($request));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(ContractTemplateService::present($t));
    }

    /** Aperçu PDF FIDÈLE (même pipeline), échantillon ou contenu non enregistré. Sans émission. */
    public function previewPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'template_id' => 'required|integer',
            'body_html' => 'sometimes|string',
        ]);
        $template = DocumentTemplate::whereNull('company_id')->where('subject_type', 'contract')->findOrFail($validated['template_id']);

        if ($request->filled('body_html')) {
            $template = $template->replicate();
            $template->body_html = \App\Core\Documents\Services\HtmlSanitizer::sanitize($validated['body_html']);
        }

        $variables = collect(DocumentVariableResolver::availableVariables('contract'))
            ->mapWithKeys(fn ($v) => [$v['key'] => '['.($v['label'] ?? $v['key']).']'])->all();
        $pdf = app(DocumentPdfGenerator::class)->renderPdf($template, $variables);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="apercu-modele.pdf"', 'X-Preview' => 'true']);
    }
}
