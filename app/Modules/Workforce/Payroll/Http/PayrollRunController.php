<?php

namespace App\Modules\Workforce\Payroll\Http;

use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use App\Modules\Workforce\ReadModels\PayrollCalculationReadModel;
use App\Modules\Workforce\ReadModels\PayrollLineReadModel;
use App\Modules\Workforce\ReadModels\PayrollRunReadModel;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use App\Modules\Workforce\UseCases\ComputePayrollUseCase;
use App\Modules\Workforce\UseCases\CreatePayrollRunUseCase;
use App\Modules\Workforce\UseCases\DeletePayrollRunUseCase;
use App\Modules\Workforce\UseCases\ExportPayrollUseCase;
use App\Modules\Workforce\UseCases\PreviewPayrollCalculationUseCase;
use App\Modules\Workforce\UseCases\RecomputePayrollUseCase;
use App\Modules\Workforce\UseCases\ValidatePayrollUseCase;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollRunController extends Controller
{
    // ── List ─────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $runs = PayrollRunReadModel::forCompany(
            companyId: $companyId,
            filters: [
                'status' => $request->input('status'),
                'period_start' => $request->input('period_start'),
                'period_end' => $request->input('period_end'),
            ],
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($runs);
    }

    // ── Detail ───────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $run = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'run' => $run,
            'lines_summary' => PayrollLineReadModel::summary($run->id),
            'calculation_totals' => PayrollCalculationReadModel::runTotals($run->id),
        ]);
    }

    // ── Create ───────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
        ]);

        try {
            $run = app(CreatePayrollRunUseCase::class)->execute(
                companyId: $companyId,
                periodStart: $validated['period_start'],
                periodEnd: $validated['period_end'],
                actorId: $actorId,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($run, 201);
    }

    // ── Compute lines ────────────────────────────────────────────────
    public function compute(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            $run = app(ComputePayrollUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($run->fresh());
    }

    // ── Compute calculations (brut→net) ──────────────────────────────
    public function computeCalculations(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            $run = app(ComputePayrollCalculationsUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($run->fresh());
    }

    // ── Validate ─────────────────────────────────────────────────────
    public function validate(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;

        $validated = $request->validate([
            'validation_note' => 'nullable|string',
        ]);

        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            $run = app(ValidatePayrollUseCase::class)->execute(
                run: $run,
                actorId: $actorId,
                validationNote: $validated['validation_note'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($run->fresh());
    }

    // ── Export ────────────────────────────────────────────────────────
    public function export(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $exportFormat = $request->input('export_format', 'json');
        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            $run = app(ExportPayrollUseCase::class)->execute($run, $actorId, $exportFormat);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($run->fresh());
    }

    // ── Recompute ────────────────────────────────────────────────────
    public function recompute(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            $run = app(RecomputePayrollUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($run->fresh());
    }

    // ── Delete ───────────────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $actorId = $request->user()->id;
        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        try {
            app(DeletePayrollRunUseCase::class)->execute($run, $actorId);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    // ── Lines ────────────────────────────────────────────────────────
    public function lines(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        $lines = PayrollLineReadModel::forRun(
            payrollRunId: $id,
            perPage: (int) $request->input('per_page', 50),
        );

        return response()->json($lines);
    }

    // ── Line Detail ─────────────────────────────────────────────────
    public function showLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        $detail = PayrollLineReadModel::detail($lineId, $companyId);
        if (! $detail || $detail['payroll_run']['id'] !== $id) {
            abort(404);
        }

        return response()->json($detail);
    }

    // ── Calculations ─────────────────────────────────────────────────
    public function calculations(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        return response()->json([
            'calculations' => PayrollCalculationReadModel::forRun($id),
            'totals' => PayrollCalculationReadModel::runTotals($id),
        ]);
    }

    // ── Run Documents ─────────────────────────────────────────────────
    public function documents(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);

        return response()->json(GeneratedDocumentReadModel::forPayrollRun($id, $companyId));
    }

    // ── Statistics ───────────────────────────────────────────────────
    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(PayrollRunReadModel::statistics($companyId));
    }
}
