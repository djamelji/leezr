<?php

namespace App\Modules\Workforce\Payroll\Http;

use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\PayrollRun;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\DsnAuditReadModel;
use App\Modules\Workforce\ReadModels\DsnDeclarationReadModel;
use App\Modules\Workforce\UseCases\ExportPayrollDsnUseCase;
use App\Modules\Workforce\UseCases\PollDsnDeclarationUseCase;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DsnDeclarationController extends Controller
{
    // ── List ─────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $declarations = DsnDeclarationReadModel::forCompany(
            companyId: $companyId,
            filters: [
                'status' => $request->input('status'),
                'period_month' => $request->input('period_month'),
            ],
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($declarations);
    }

    // ── Detail ───────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $declaration = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'declaration' => DsnDeclarationReadModel::detail($declaration->id),
            'validation_summary' => DsnDeclarationReadModel::validationSummary($declaration->id),
            'audit_history' => DsnAuditReadModel::historyForDeclaration($declaration->id),
        ]);
    }

    // ── Statistics ───────────────────────────────────────────────────
    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(DsnDeclarationReadModel::statistics($companyId));
    }

    // ── Export DSN from payroll run ──────────────────────────────────
    public function export(Request $request): JsonResponse
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
            $declaration = app(ExportPayrollDsnUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($declaration, 201);
    }

    // ── Submit to gateway ───────────────────────────────────────────
    public function submit(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $declaration = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $declaration = app(SubmitDsnDeclarationUseCase::class)->execute($declaration, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($declaration);
    }

    // ── Poll gateway status ─────────────────────────────────────────
    public function poll(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $declaration = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        try {
            $result = app(PollDsnDeclarationUseCase::class)->execute($declaration, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'declaration' => $result['declaration'],
            'result' => $result['result'],
            'gateway_status' => $result['gateway_status'],
        ]);
    }

    // ── Validation errors ───────────────────────────────────────────
    public function validationErrors(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json(DsnDeclarationReadModel::validationSummary($id));
    }

    // ── Audit history ───────────────────────────────────────────────
    public function history(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json(DsnAuditReadModel::historyForDeclaration($id));
    }
}
