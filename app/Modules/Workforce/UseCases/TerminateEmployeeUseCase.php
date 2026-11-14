<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TerminateEmployeeUseCase
{
    public function execute(Employee $employee, string $reason, ?Carbon $terminationDate = null): Employee
    {
        $terminationDate ??= now();

        // ADR-772 : les refus métier sont des erreurs explicites (422), plus des exceptions non gérées (500).
        if (! $employee->canTransitionTo(Employee::STATUS_TERMINATED)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ["La fin de collaboration ne peut pas être enregistrée pour un collaborateur au statut « {$employee->status} »."],
            ]);
        }

        return DB::transaction(function () use ($employee, $reason, $terminationDate) {
            $employee->transitionTo(Employee::STATUS_TERMINATED);
            $employee->termination_date = $terminationDate;
            $employee->save();

            // Terminate current contract if exists
            $currentContract = $employee->currentContract;
            // ADR-773 : un contrat EN VIGUEUR se termine ; un BROUILLON (jamais entré en vigueur) est
            // abandonné — jamais activé artificiellement, jamais « terminé ». La sortie n'est plus bloquée.
            if ($currentContract && $currentContract->status === \App\Core\Workforce\EmploymentContract::STATUS_DRAFT) {
                app(CancelDraftContractUseCase::class)->execute($currentContract, auth()->user(), 'Fin de collaboration : '.$reason);
            } elseif ($currentContract && $currentContract->canTransitionTo('terminated')) {
                $currentContract->transitionTo('terminated');
                $currentContract->is_current = false;
                $currentContract->save();
            }
            // Autres brouillons éventuels du collaborateur sortant : abandonnés eux aussi.
            foreach (\App\Core\Workforce\EmploymentContract::withoutGlobalScopes()->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)->where('status', \App\Core\Workforce\EmploymentContract::STATUS_DRAFT)->get() as $draft) {
                app(CancelDraftContractUseCase::class)->execute($draft, auth()->user(), 'Fin de collaboration : '.$reason);
            }

            app(AuditLogger::class)->logCompany(
                companyId: $employee->company_id,
                action: 'employee.terminated',
                targetType: 'employee',
                targetId: (string) $employee->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.employee',
                        'reason' => $reason,
                        'termination_date' => $terminationDate->toDateString(),
                        'description' => "Employee {$employee->full_name} terminated",
                    ],
                ],
            );

            return $employee;
        });
    }
}
