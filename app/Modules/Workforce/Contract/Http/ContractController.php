<?php

namespace App\Modules\Workforce\Contract\Http;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Models\Company;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Contract\Services\ContractPreviewService;
use App\Modules\Workforce\Contract\Services\ContractTemplateService;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\GenerateDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W-CONTRACT-1 (ADR-647) — API du module Contrats de travail : bibliothèque de modèles (Platform +
 * Company), édition/versionnement/publication des modèles Company, palette de variables contrôlée,
 * prévisualisation (sans émission) et génération documentaire (réutilise GenerateDocumentUseCase).
 */
class ContractController extends Controller
{
    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->attributes->get('company_id'));
    }

    // ── Bibliothèque de modèles ──────────────────────────────────────
    public function templates(Request $request): JsonResponse
    {
        return response()->json(['data' => ContractTemplateService::available(
            $this->company($request),
            ['contract_type' => $request->query('contract_type')],
        )]);
    }

    public function showTemplate(Request $request, int $id): JsonResponse
    {
        $t = DocumentTemplate::where('subject_type', ContractTemplateService::SUBJECT)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $request->attributes->get('company_id')))
            ->findOrFail($id);

        return response()->json(array_merge(ContractTemplateService::present($t), [
            'body_html' => $t->body_html, 'header_html' => $t->header_html, 'footer_html' => $t->footer_html,
        ]));
    }

    /** Palette de variables métier CONTRÔLÉE (registre) — aucune exécution de code arbitraire. */
    public function variables(Request $request): JsonResponse
    {
        $schema = DocumentVariableResolver::availableVariables(ContractTemplateService::SUBJECT);
        // Masque les variables de rémunération sans workforce.compensation_read.
        if (! WorkforceAccess::canReadCompensation($request)) {
            $schema = array_values(array_filter($schema, fn ($v) => ! ContractPreviewService::isCompensationVar($v['key'] ?? '')));
        }

        return response()->json(['data' => $schema]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $t = ContractTemplateService::duplicateFromPlatform($this->company($request), $id, $request->user()->id);

        return response()->json(ContractTemplateService::present($t), 201);
    }

    public function createVersion(Request $request, int $id): JsonResponse
    {
        $t = ContractTemplateService::createVersion($this->company($request), $id, $request->user()->id);

        return response()->json(ContractTemplateService::present($t), 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'body_html' => 'sometimes|string',
            'header_html' => 'sometimes|nullable|string',
            'footer_html' => 'sometimes|nullable|string',
            'contract_type' => 'sometimes|nullable|string|max:50',
            'legal_status' => 'sometimes|nullable|string|max:50',
        ]);

        try {
            $t = ContractTemplateService::update($this->company($request), $id, $validated, $request->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(ContractTemplateService::present($t));
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $t = ContractTemplateService::publish($this->company($request), $id, $request->user()->id);

        return response()->json(ContractTemplateService::present($t));
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $t = ContractTemplateService::archive($this->company($request), $id, $request->user()->id);

        return response()->json(ContractTemplateService::present($t));
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        try {
            $t = ContractTemplateService::setDefault($this->company($request), $id, $request->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(ContractTemplateService::present($t));
    }

    // ── Prévisualisation (SANS émission) ─────────────────────────────
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => 'required|integer',
            'employee_id' => 'required|integer',
        ]);
        $companyId = $request->attributes->get('company_id');

        abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $validated['employee_id'], 'employee'), 403);

        $resolved = ContractPreviewService::resolveContractSubject($companyId, (int) $validated['employee_id']);
        if ($resolved['error']) {
            return response()->json(['message' => $resolved['error']], 422);
        }
        $template = ContractPreviewService::template($companyId, (int) $validated['template_id']);

        // Prévisualisation : masque le salaire si l'utilisateur n'a pas la permission.
        $variables = ContractPreviewService::variables($request, $resolved['contract'], $this->company($request));

        $used = $template->extractUsedVariables();
        $missing = array_values(array_diff($used, array_keys($variables)));
        $html = ContractPreviewService::render(($template->header_html ?? '').$template->body_html.($template->footer_html ?? ''), $variables);

        return response()->json([
            'preview' => true,                      // JAMAIS un document émis ni signé
            'html' => $html,
            'missing_variables' => $missing,
            'template' => ContractTemplateService::present($template),
        ]);
    }

    /**
     * W-CONTRACT-2 — APERÇU PDF FIDÈLE : même pipeline que la génération (DocumentPdfGenerator),
     * en mémoire, SANS émission (aucun GeneratedDocument, aucune modification métier, non signé).
     * Avec employee_id → données réelles (scope + salaire masqué) ; sinon → échantillon (Platform).
     */
    public function previewPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'template_id' => 'required|integer',
            'employee_id' => 'sometimes|integer',
            // W-CONTRACT-3 : aperçu du contenu NON ENREGISTRÉ (édition en cours) — passe par la MÊME
            // sanitation + résolution + génération. Aucune mutation persistante.
            'body_html' => 'sometimes|string',
            'header_html' => 'sometimes|nullable|string',
            'footer_html' => 'sometimes|nullable|string',
        ]);
        $companyId = $request->attributes->get('company_id');

        $template = ContractPreviewService::template($companyId, (int) $validated['template_id']);

        // Contenu non enregistré : on applique la sanitation SERVEUR et on travaille sur une copie
        // EN MÉMOIRE (jamais sauvegardée). L'édition d'un contenu non enregistré ne contourne donc
        // ni la sanitation ni les permissions (le gating `generate` + scope restent appliqués).
        if ($request->filled('body_html') || $request->has('header_html') || $request->has('footer_html')) {
            $template = $template->replicate();
            if ($request->filled('body_html')) {
                $template->body_html = \App\Core\Documents\Services\HtmlSanitizer::sanitize($validated['body_html']);
            }
            if ($request->has('header_html')) {
                $template->header_html = \App\Core\Documents\Services\HtmlSanitizer::sanitize($validated['header_html'] ?? '');
            }
            if ($request->has('footer_html')) {
                $template->footer_html = \App\Core\Documents\Services\HtmlSanitizer::sanitize($validated['footer_html'] ?? '');
            }
        }

        if ($request->filled('employee_id')) {
            abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $validated['employee_id'], 'employee'), 403);
            $resolved = ContractPreviewService::resolveContractSubject($companyId, (int) $validated['employee_id']);
            if ($resolved['error']) {
                return response()->json(['message' => $resolved['error']], 422);
            }
            $variables = ContractPreviewService::variables($request, $resolved['contract'], $this->company($request));
        } else {
            // Contexte Platform / échantillon : chaque variable rendue par son libellé entre crochets.
            $variables = collect(DocumentVariableResolver::availableVariables('contract'))
                ->mapWithKeys(fn ($v) => [$v['key'] => '['.($v['label'] ?? $v['key']).']'])->all();
        }

        $pdf = app(\App\Core\Documents\Services\DocumentPdfGenerator::class)->renderPdf($template, $variables);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="apercu-contrat.pdf"',
            'X-Preview' => 'true', // Aperçu — document non émis
        ]);
    }

    // ── Génération (émission + archivage) ────────────────────────────
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_code' => 'required|string',
            'employee_id' => 'required|integer',
        ]);
        $companyId = $request->attributes->get('company_id');

        abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $validated['employee_id'], 'employee'), 403);

        $resolved = ContractPreviewService::resolveContractSubject($companyId, (int) $validated['employee_id']);
        if ($resolved['error']) {
            return response()->json(['message' => $resolved['error']], 422);
        }
        if ($refusal = \App\Company\Fields\ReadModels\CompanyLegalIdentity::contractRefusal($companyId)) {
            return response()->json($refusal, 422); // ADR-783 : adresse employeur requise (profil Company)
        }

        try {
            $doc = app(GenerateDocumentUseCase::class)->execute(
                companyId: $companyId,
                templateCode: $validated['template_code'],
                subjectType: 'contract',
                subjectId: $resolved['contract']->id,
                actorId: $request->user()->id,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(\App\Modules\Workforce\Security\WorkforceAccess::canReadCompensation($request) ? $doc : $doc->makeHidden('generation_snapshot'), 201); // ADR-771 : l'instantané porte la rémunération
    }
}
