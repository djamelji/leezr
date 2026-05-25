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
}
