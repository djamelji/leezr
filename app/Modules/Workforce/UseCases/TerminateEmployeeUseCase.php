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

        // Règle contractuelle : seul un contrat EN VIGUEUR (actif ou suspendu) se termine. Un contrat
        // encore en brouillon n'a jamais pris effet — il ne peut pas être « terminé », et le laisser
        // courant sur un collaborateur sorti casserait l'historique contractuel. Rien n'est écrit.
        $current = $employee->currentContract;
        if ($current && ! in_array($current->status, ['terminated'], true) && ! $current->canTransitionTo('terminated')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contract' => ["Le contrat en cours est au statut « {$current->status} » : activez-le avant d'enregistrer la fin de collaboration."],
            ]);
        }

        return DB::transaction(function () use ($employee, $reason, $terminationDate) {
            $employee->transitionTo(Employee::STATUS_TERMINATED);
            $employee->termination_date = $terminationDate;
            $employee->save();

            // Terminate current contract if exists
            $currentContract = $employee->currentContract;
            if ($currentContract && $currentContract->status !== 'terminated') {
                $currentContract->transitionTo('terminated');
                $currentContract->is_current = false;
                $currentContract->save();
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
