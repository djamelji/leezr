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

        if (! $employee->canTransitionTo(Employee::STATUS_TERMINATED)) {
            throw new \DomainException("Cannot terminate employee in status {$employee->status}");
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
