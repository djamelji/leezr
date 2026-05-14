<?php

namespace App\Modules\Workforce\Documents\Http;

use App\Core\Documents\GeneratedDocument;
use App\Core\Workforce\PayrollRun;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use App\Modules\Workforce\UseCases\GenerateDocumentUseCase;
use App\Modules\Workforce\UseCases\GenerateOfficialPayslipsUseCase;
use App\Modules\Workforce\UseCases\GeneratePayslipDraftsUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ],
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($documents);
    }

    // ── Detail ───────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $document = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json($document);
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

        return response()->json(GeneratedDocumentReadModel::forPayrollRun($runId, $companyId));
    }
}
