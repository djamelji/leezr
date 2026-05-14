<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Data\CreateEmployeeData;

class CreateEmployeeUseCase
{
    public function execute(CreateEmployeeData $data): Employee
    {
        $employee = Employee::create([
            'company_id' => $data->companyId,
            'user_id' => $data->userId,
            'employee_number' => $data->employeeNumber,
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->email,
            'phone' => $data->phone,
            'hire_date' => $data->hireDate,
            'status' => $data->status,
            'metadata' => $data->metadata,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $data->companyId,
            action: 'employee.created',
            targetType: 'employee',
            targetId: (string) $employee->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.employee',
                    'description' => "Employee {$employee->full_name} created",
                ],
            ],
        );

        return $employee;
    }
}
