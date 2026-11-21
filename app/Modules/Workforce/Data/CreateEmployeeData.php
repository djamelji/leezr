<?php

namespace App\Modules\Workforce\Data;

class CreateEmployeeData
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $hireDate,
        public readonly ?int $userId = null,
        public readonly ?string $employeeNumber = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly string $status = 'active',
        public readonly ?array $metadata = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $jobRoleId = null,
        public readonly ?int $managerId = null,
    ) {}

    /** ADR-848 : from the validated request of EmployeeController::store (moved verbatim). */
    public static function fromValidated(int $companyId, array $validated): self
    {
        return new self(
            companyId: $companyId,
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            hireDate: $validated['hire_date'],
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            employeeNumber: $validated['employee_number'] ?? null,
            departmentId: $validated['department_id'] ?? null,
            jobRoleId: $validated['job_role_id'] ?? null,
            managerId: $validated['manager_id'] ?? null,
        );
    }
}
