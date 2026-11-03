<?php

namespace App\Modules\Workforce\Documents\Http;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollRun;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use App\Modules\Workforce\Security\PayrollConfidentiality;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\GenerateDocumentUseCase;
use App\Modules\Workforce\UseCases\GenerateOfficialPayslipsUseCase;
use App\Modules\Workforce\UseCases\GeneratePayslipDraftsUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkforceDocumentController extends Controller
{
    // ── List ─────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $documents = GeneratedDocumentReadModel::forCompany(
            companyId: $companyId,
            filters: [
                'subject_type' => $request->input('subject_type'),
                'subject_id' => $request->input('subject_id'),
                'template_code' => $request->input('template_code'),
                'signature_status' => $request->input('signature_status'),
                // PRODUCT-UX-2 (LOT A2) — registre transversal.
                'employee_id' => $request->input('employee_id'),
                'search' => $request->input('search'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
            perPage: (int) $request->input('per_page', 15),
        );

        // P2 (ADR-684): the registry lists ALL subject types incl. payslips/contracts whose
        // generation_snapshot embeds salaries → scrub for viewers without compensation_read.
        return response()->json(PayrollConfidentiality::scrub($documents, PayrollConfidentiality::canRead($request)));
    }

    /**
     * W-G1 (ADR-641) — modèles documentaires DISPONIBLES pour la Company, pour alimenter les
     * sélecteurs métier (fin de la saisie d'un code template à l'aveugle). Résolution identique
     * au moteur de génération : surcharge Company > plateforme (par `code`), actifs uniquement,
     * tenant-safe (jamais les templates d'une autre Company). Filtre optionnel par subject_type
     * (ne propose jamais un modèle incompatible).
     */
    public function templates(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $subjectType = $request->query('subject_type');

        if ($subjectType !== null && ! in_array($subjectType, DocumentTemplate::SUBJECT_TYPES, true)) {
            return response()->json(['data' => []]);
        }

        $query = DocumentTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });

        if ($subjectType !== null) {
            $query->where('subject_type', $subjectType);
        }

        // Company override (platform_template_id = celui de plateforme) gagne sur la plateforme, par `code`.
        $resolved = [];
        foreach ($query->orderBy('name')->get() as $tpl) {
            $key = $tpl->code;
            $isCompany = $tpl->company_id !== null;
            if (! isset($resolved[$key]) || $isCompany) {
                $resolved[$key] = [
                    'code' => $tpl->code,
                    'name' => $tpl->name,
                    'description' => $tpl->description,
                    'subject_type' => $tpl->subject_type,
                    'source' => $isCompany ? 'company' : 'platform',
                ];
            }
        }

        return response()->json(['data' => array_values($resolved)]);
    }

    // ── Detail ───────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $document = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        // P2 (ADR-684): generation_snapshot may embed salaries — scrub for non-comp viewers.
        return response()->json(PayrollConfidentiality::scrub($document, PayrollConfidentiality::canRead($request)));
    }

    // ── Download (ADR-634) — route authentifiée + tenant + scope collaborateur ────────
    // Remplace l'ancien accès via `file_path` brut. Les documents signés restent immuables.
    public function download(Request $request, int $id): StreamedResponse
    {
        $companyId = $request->attributes->get('company_id');

        $document = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->assertSubjectScope($request, $document);

        $disk = config('documents.generated_disk', 'local');
        abort_unless(
            $document->file_path && Storage::disk($disk)->exists($document->file_path),
            404,
            'Document file not found.'
        );

        return Storage::disk($disk)->download($document->file_path, basename($document->file_path));
    }

    /** Scope collaborateur pour les documents individuels (employé/contrat). */
    private function assertSubjectScope(Request $request, GeneratedDocument $document): void
    {
        if ($document->subject_type === 'employee') {
            abort_unless(
                WorkforceAccess::canViewEmployeeData($request, (int) $document->subject_id, 'employee'),
                403,
                'Not authorized to download this employee document.'
            );
        } elseif ($document->subject_type === 'contract') {
            $employeeId = EmploymentContract::withoutGlobalScopes()
                ->where('company_id', $document->company_id)
                ->where('id', $document->subject_id)
                ->value('employee_id');
            if ($employeeId) {
                abort_unless(
                    WorkforceAccess::canViewEmployeeData($request, (int) $employeeId, 'employee'),
                    403,
                    'Not authorized to download this contract.'
                );
            }
        } elseif (in_array($document->subject_type, ['payroll_line', 'payroll_run'], true)) {
            // C3 (ADR-681): payslips embed salary — downloading one must not bypass
            // compensation_read via the documents surface. Require it on top of
            // workforce_documents.view (route middleware).
            abort_unless(
                WorkforceAccess::canReadCompensation($request),
                403,
                'Not authorized to download payroll documents without compensation access.'
            );
        }
        // leave_request : géré par la permission workforce_documents.view.
    }

    // ── Statistics ───────────────────────────────────────────────────
    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(GeneratedDocumentReadModel::statistics($companyId));
    }

    // ── Generate single document ────────────────────────────────────
    public function generate(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $validated = $request->validate([
            'template_code' => 'required|string',
            'subject_type' => 'required|string',
            'subject_id' => 'required|integer',
            'force_vault' => 'sometimes|boolean',
        ]);

        try {
            $document = app(GenerateDocumentUseCase::class)->execute(
                companyId: $companyId,
                templateCode: $validated['template_code'],
                subjectType: $validated['subject_type'],
                subjectId: $validated['subject_id'],
                actorId: $actorId,
                forceVault: $validated['force_vault'] ?? false,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($document, 201);
    }

    // ── Generate payslip drafts ─────────────────────────────────────
    public function generatePayslipDrafts(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $validated = $request->validate([
            'payroll_run_id' => 'required|integer',
        ]);

        $run = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($validated['payroll_run_id']);

        try {
            $documents = app(GeneratePayslipDraftsUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($documents, 201);
    }

    // ── Generate official payslips ──────────────────────────────────
    public function generateOfficialPayslips(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $validated = $request->validate([
            'payroll_run_id' => 'required|integer',
        ]);

        $run = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($validated['payroll_run_id']);

        try {
            $documents = app(GenerateOfficialPayslipsUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($documents, 201);
    }

    // ── Documents for a payroll run ─────────────────────────────────
    public function forPayrollRun(Request $request, int $runId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($runId);

        // Payslip documents embed salary in generation_snapshot → scrub for non-comp.
        return response()->json(PayrollConfidentiality::scrub(
            GeneratedDocumentReadModel::forPayrollRun($runId, $companyId),
            PayrollConfidentiality::canRead($request),
        ));
    }
}
