<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestLeaveUseCase
{
    /**
     * Create a new leave request with status=pending.
     *
     * Guards:
     *  - Employee must be active
     *  - Employee must have an active current contract
     *  - Leave type must be enabled
     *  - No date overlap with approved/consumed requests for this employee
     *  - Sufficient balance (unless allow_negative policy)
     */
    public function execute(
        Employee $employee,
        int $leaveTypeId,
        string $dateFrom,
        string $dateTo,
        int $daysCountHundredths,
        ?string $reason = null,
        bool $allowNegative = false,
    ): LeaveRequest {
        // Guard: employee must be active
        if ($employee->status !== Employee::STATUS_ACTIVE) {
            throw new \DomainException("Employee must be active to request leave. Current status: {$employee->status}");
        }

        // Guard: employee must have an active current contract
        $contract = $employee->currentContract;

        if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
            throw new \DomainException('Employee must have an active current contract to request leave.');
        }

        // Guard: leave type must be enabled
        $leaveType = LeaveType::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('id', $leaveTypeId)
            ->first();

        if (! $leaveType || ! $leaveType->enabled) {
            throw new \DomainException('Leave type is not enabled or does not exist.');
        }

        // Guard: no date overlap with approved/consumed requests
        $overlap = LeaveRequest::withoutCompanyScope()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED, LeaveRequest::STATUS_PENDING])
            ->overlapping($dateFrom, $dateTo)
            ->exists();

        if ($overlap) {
            throw new \DomainException('Date range overlaps with an existing leave request.');
        }

        // Guard: sufficient balance (unless allow_negative)
        if (! $allowNegative) {
            $balance = LeaveLedger::computeBalance(
                $employee->company_id,
                $employee->id,
                $leaveTypeId,
            );

            if ($balance['available'] < $daysCountHundredths) {
                throw new \DomainException(
                    "Insufficient leave balance. Available: {$balance['available']}, requested: {$daysCountHundredths}"
                );
            }
        }

        return DB::transaction(function () use (
            $employee, $leaveTypeId, $dateFrom, $dateTo, $daysCountHundredths, $reason, $leaveType,
        ) {
            $request = LeaveRequest::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'days_count_hundredths' => $daysCountHundredths,
                'status' => LeaveRequest::STATUS_PENDING,
                'reason' => $reason,
            ]);

            app(AuditLogger::class)->logCompany(
                companyId: $employee->company_id,
                action: 'leave_request.created',
                targetType: 'leave_request',
                targetId: (string) $request->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.leave.request',
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveTypeId,
                        'leave_type_code' => $leaveType->code,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'days_count_hundredths' => $daysCountHundredths,
                    ],
                ],
            );

            return $request;
        });
    }
}
