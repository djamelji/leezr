<?php

namespace App\Modules\Workforce\Contract\Http;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Http\Controllers\Controller;
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
            $schema = array_values(array_filter($schema, fn ($v) => ! self::isCompensationVar($v['key'] ?? '')));
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

    // ── Prévisualisation (SANS émission) ─────────────────────────────
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => 'required|integer',
            'employee_id' => 'required|integer',
        ]);
        $companyId = $request->attributes->get('company_id');

        abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $validated['employee_id'], 'employee'), 403);

        $resolved = $this->resolveContractSubject($companyId, (int) $validated['employee_id']);
        if ($resolved['error']) {
            return response()->json(['message' => $resolved['error']], 422);
        }
        $template = DocumentTemplate::where('subject_type', ContractTemplateService::SUBJECT)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->findOrFail($validated['template_id']);

        $variables = DocumentVariableResolver::resolve('contract', $resolved['contract'], $this->company($request));
        // Prévisualisation : masque le salaire si l'utilisateur n'a pas la permission.
        if (! WorkforceAccess::canReadCompensation($request)) {
            foreach (array_keys($variables) as $k) {
                if (self::isCompensationVar($k)) {
                    $variables[$k] = '•••';
                }
            }
        }

        $used = $template->extractUsedVariables();
        $missing = array_values(array_diff($used, array_keys($variables)));
        $html = self::render(($template->header_html ?? '').$template->body_html.($template->footer_html ?? ''), $variables);

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
        ]);
        $companyId = $request->attributes->get('company_id');

        $template = DocumentTemplate::where('subject_type', ContractTemplateService::SUBJECT)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->findOrFail($validated['template_id']);

        if ($request->filled('employee_id')) {
            abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $validated['employee_id'], 'employee'), 403);
            $resolved = $this->resolveContractSubject($companyId, (int) $validated['employee_id']);
            if ($resolved['error']) {
                return response()->json(['message' => $resolved['error']], 422);
            }
            $variables = DocumentVariableResolver::resolve('contract', $resolved['contract'], $this->company($request));
            if (! WorkforceAccess::canReadCompensation($request)) {
                foreach (array_keys($variables) as $k) {
                    if (self::isCompensationVar($k)) {
                        $variables[$k] = '•••';
                    }
                }
            }
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

        $resolved = $this->resolveContractSubject($companyId, (int) $validated['employee_id']);
        if ($resolved['error']) {
            return response()->json(['message' => $resolved['error']], 422);
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

        return response()->json($doc, 201);
    }

    /** Résout le contrat métier COURANT du collaborateur (jamais créé automatiquement). */
    private function resolveContractSubject(int $companyId, int $employeeId): array
    {
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->find($employeeId);
        if (! $employee) {
            return ['error' => 'Collaborateur introuvable.', 'contract' => null];
        }
        $contract = EmploymentContract::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->where('is_current', true)->first()
            ?? EmploymentContract::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employeeId)->latest('start_date')->first();
        if (! $contract) {
            return ['error' => 'Aucun contrat de travail pour ce collaborateur. Créez-le d\'abord dans sa fiche.', 'contract' => null];
        }

        return ['error' => null, 'contract' => $contract];
    }

    /** Substitution STRICTE {{ var }} — aucune exécution de code (pas de Blade/PHP/JS). */
    private static function render(string $html, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($variables) {
            $v = $variables[$m[1]] ?? '';

            return htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES);
        }, $html);
    }

    private static function isCompensationVar(string $key): bool
    {
        return (bool) preg_match('/salary|compensation|rate|remuneration|salaire/i', $key);
    }
}
