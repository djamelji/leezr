<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\FinancialPeriod;
use App\Core\Billing\ReadModels\PlatformFinancialReadService;
use App\Core\Billing\ReconciliationEngine;
use App\Core\Models\Company;
use App\Modules\Platform\Billing\Http\Requests\FinancialFreezeRequest;
use App\Modules\Platform\Billing\Http\Requests\PeriodCloseRequest;
use App\Modules\Platform\Billing\Http\Requests\ReconcileRequest;
use App\Modules\Platform\Billing\Http\Requests\TrialBalanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-144 D4b: Platform financial governance HTTP layer.
 *
 * Exposes D3 financial services (Ledger, Periods, Forensics, Reconciliation)
 * via HTTP for the platform admin billing UI.
 *
 * Read endpoints require view_billing permission.
 * Write endpoints require manage_billing permission.
 */
class PlatformFinancialController
{
    // ── Read (view_billing) ──────────────────────────────────

    public function trialBalance(TrialBalanceRequest $request): JsonResponse
    {
        $balance = PlatformFinancialReadService::trialBalance(
            $request->integer('company_id'),
        );

        return response()->json(['balance' => $balance]);
    }

    public function ledgerEntries(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'correlation_id' => ['nullable', 'string', 'max:255'],
            'entry_type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = PlatformFinancialReadService::ledgerEntries(
            $request->integer('company_id'),
            $request->input('correlation_id'),
            $request->input('entry_type'),
            $request->integer('per_page', 50),
        );

        return response()->json($paginator);
    }

    public function financialPeriods(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $periods = PlatformFinancialReadService::financialPeriods(
            $request->integer('company_id'),
        );

        return response()->json(['periods' => $periods]);
    }

    public function forensicsTimeline(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'entity_type' => ['nullable', 'string', 'max:50'],
        ]);

        $timeline = PlatformFinancialReadService::forensicsTimeline(
            $request->integer('company_id'),
            $request->integer('days', 30),
            $request->input('entity_type'),
        );

        return response()->json(['timeline' => $timeline]);
    }

    public function forensicsSnapshots(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = PlatformFinancialReadService::forensicsSnapshots(
            $request->integer('company_id'),
            $request->integer('per_page', 50),
        );

        return response()->json($paginator);
    }

    public function driftHistory(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $drifts = PlatformFinancialReadService::driftHistory(
            $request->integer('company_id') ?: null,
        );

        return response()->json(['drifts' => $drifts]);
    }

    // ── Write (manage_billing) ───────────────────────────────

    public function closePeriod(PeriodCloseRequest $request): JsonResponse
    {
        $period = FinancialPeriod::updateOrCreate(
            [
                'company_id' => $request->integer('company_id'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
            ],
            [
                'is_closed' => true,
                'closed_at' => now(),
            ],
        );

        return response()->json(['period' => $period]);
    }

    public function freezeState(int $id): JsonResponse
    {
        $company = Company::find($id);

        if (! $company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        return response()->json([
            'company_id' => $company->id,
            'financial_freeze' => $company->financial_freeze,
        ]);
    }

    public function toggleFreeze(FinancialFreezeRequest $request, int $id): JsonResponse
    {
        $company = Company::find($id);

        if (! $company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $company->update(['financial_freeze' => $request->boolean('frozen')]);

        return response()->json([
            'company_id' => $company->id,
            'financial_freeze' => $company->financial_freeze,
        ]);
    }

    public function reconcile(ReconcileRequest $request): JsonResponse
    {
        $result = ReconciliationEngine::reconcile(
            companyId: $request->integer('company_id') ?: null,
            dryRun: $request->boolean('dry_run', true),
            autoRepair: false,
        );

        return response()->json($result);
    }
}
