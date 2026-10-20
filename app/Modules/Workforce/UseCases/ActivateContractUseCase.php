<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Support\Facades\DB;

/**
 * LOT G (ANO-016) — activating a contract enforces the single-active / single-current invariants.
 * Activating a contract supersedes (terminates) any OTHER active contract of the same employee —
 * the succession case (e.g. activating a CDI ends the preceding CDD) — and makes the activated
 * contract the sole `is_current` one. This closes the previously observed bug where two contracts
 * could both be `status=active` with `is_current` decoupled from `status`.
 *
 * NOTE: genuine period overlap between active contracts is already prevented at creation
 * (CreateContractUseCase), so superseding here only ever resolves a non-overlapping succession.
 */
class ActivateContractUseCase
{
    public function execute(EmploymentContract $contract): EmploymentContract
    {
        if (! $contract->canTransitionTo(EmploymentContract::STATUS_ACTIVE)) {
            throw new \DomainException('Cannot activate contract in its current status.');
        }

        return DB::transaction(function () use ($contract) {
            // Supersede any other active contract (invariant: at most one active per employee).
            $superseded = EmploymentContract::where('employee_id', $contract->employee_id)
                ->where('id', '!=', $contract->id)
                ->where('status', EmploymentContract::STATUS_ACTIVE)
                ->get();

            foreach ($superseded as $prev) {
                $prev->transitionTo(EmploymentContract::STATUS_TERMINATED);
                $prev->is_current = false;
                $prev->save();

                app(AuditLogger::class)->logCompany(
                    companyId: $prev->company_id,
                    action: 'contract.superseded',
                    targetType: 'employment_contract',
                    targetId: (string) $prev->id,
                    options: [
                        'metadata' => [
                            'category' => 'workforce.contract',
                            'superseded_by' => $contract->id,
                        ],
                    ],
                );
            }

            // Demote any other current flag (invariant: at most one is_current per employee).
            EmploymentContract::where('employee_id', $contract->employee_id)
                ->where('id', '!=', $contract->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $contract->transitionTo(EmploymentContract::STATUS_ACTIVE);
            $contract->is_current = true;
            $contract->save();

            app(AuditLogger::class)->logCompany(
                companyId: $contract->company_id,
                action: 'contract.activated',
                targetType: 'employment_contract',
                targetId: (string) $contract->id,
                options: ['metadata' => ['category' => 'workforce.contract']],
            );

            return $contract->fresh();
        });
    }
}
