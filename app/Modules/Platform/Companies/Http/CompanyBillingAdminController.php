<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\CompanyWalletTransaction;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-272: Platform admin billing actions — plan change + wallet.
 */
class CompanyBillingAdminController
{
    public function planChangePreview(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'interval' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $company = Company::findOrFail($id);
        $preview = CompanyBillingReadService::planChangePreview(
            $company,
            $request->query('plan_key'),
            $request->query('interval', 'monthly'),
        );

        if (! $preview) {
            return response()->json([
                'message' => 'No active subscription for this company.',
            ], 422);
        }

        return response()->json($preview);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'interval' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $company = Company::findOrFail($id);

        try {
            $intent = PlanChangeExecutor::schedule(
                company: $company,
                toPlanKey: $validated['plan_key'],
                toInterval: $validated['interval'] ?? 'monthly',
                timing: 'immediate',
                idempotencyKey: "admin-plan-change-{$company->id}-{$validated['plan_key']}-".now()->timestamp,
            );

            $company->refresh()->loadCount('memberships');

            return response()->json([
                'message' => 'Plan updated.',
                'company' => $company,
                'intent' => $intent,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function adjustWallet(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:credit,debit'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $company = Company::findOrFail($id);

        try {
            if ($validated['type'] === 'credit') {
                $transaction = WalletLedger::credit(
                    company: $company,
                    amount: $validated['amount'],
                    sourceType: 'admin_adjustment',
                    description: $validated['reason'],
                    actorType: 'admin',
                    actorId: auth()->id(),
                );
            } else {
                $transaction = WalletLedger::debit(
                    company: $company,
                    amount: $validated['amount'],
                    sourceType: 'admin_adjustment',
                    description: $validated['reason'],
                    actorType: 'admin',
                    actorId: auth()->id(),
                );
            }

            app(AuditLogger::class)->logPlatform(
                AuditAction::WALLET_ADMIN_CREDIT,
                'company',
                (string) $company->id,
                [
                    'diffBefore' => ['balance' => $transaction->balance_after - ($validated['type'] === 'credit' ? $validated['amount'] : -$validated['amount'])],
                    'diffAfter' => ['balance' => $transaction->balance_after],
                    'metadata' => [
                        'type' => $validated['type'],
                        'amount' => $validated['amount'],
                        'reason' => $validated['reason'],
                    ],
                ],
            );

            return response()->json([
                'message' => ucfirst($validated['type']).' applied.',
                'balance' => WalletLedger::balance($company),
                'transaction' => $transaction,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function walletHistory(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);
        $wallet = $company->wallet;

        if (! $wallet) {
            return response()->json(['transactions' => [], 'total' => 0]);
        }

        $transactions = CompanyWalletTransaction::where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => $t->amount,
                'balance_after' => $t->balance_after,
                'source_type' => $t->source_type,
                'description' => $t->description,
                'actor_type' => $t->actor_type,
                'created_at' => $t->created_at,
            ]);

        return response()->json([
            'transactions' => $transactions,
            'total' => $transactions->count(),
        ]);
    }
}
