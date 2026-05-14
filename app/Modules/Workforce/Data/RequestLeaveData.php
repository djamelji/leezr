<?php

namespace App\Modules\Workforce\Data;

class RequestLeaveData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $employeeId,
        public readonly int $leaveTypeId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly int $daysCountHundredths,
        public readonly ?string $reason = null,
        public readonly ?array $metadata = null,
    ) {}
}
