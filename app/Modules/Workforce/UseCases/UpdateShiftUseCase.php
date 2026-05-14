<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WorkLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateShiftUseCase
{
    public function execute(Shift $shift, array $data): Shift
    {
        // I9: Immutabilité published
        if ($shift->status !== Shift::STATUS_DRAFT) {
            throw new \DomainException('Only draft shifts can be modified.');
        }

        return DB::transaction(function () use ($shift, $data) {
            $startAt = isset($data['start_at']) ? Carbon::parse($data['start_at']) : $shift->start_at;
            $endAt = isset($data['end_at']) ? Carbon::parse($data['end_at']) : $shift->end_at;
            $date = $data['date'] ?? $shift->date->toDateString();
            $employeeId = $data['employee_id'] ?? $shift->employee_id;

            // I6: Cohérence temporelle
            if ($endAt->lte($startAt)) {
                throw new \DomainException('Shift end_at must be after start_at.');
            }

            // If employee changed, validate new employee
            if ($employeeId !== $shift->employee_id) {
                $employee = Employee::withoutGlobalScopes()
                    ->where('id', $employeeId)
                    ->where('company_id', $shift->company_id)
                    ->first();

                if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
                    throw new \DomainException('Employee must be active to be assigned a shift.');
                }

                $contract = $employee->currentContract;
                if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
                    throw new \DomainException('Employee must have an active contract.');
                }
            }

            // I1: Leave check on new date
            $hasLeave = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $shift->company_id)
                ->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                ->where('date_from', '<=', $date)
                ->where('date_to', '>=', $date)
                ->exists();

            if ($hasLeave) {
                throw new \DomainException("Cannot assign shift: employee has approved leave on {$date}.");
            }

            // I2: Double booking (exclude self)
            $hasOverlap = Shift::withoutGlobalScopes()
                ->where('company_id', $shift->company_id)
                ->where('employee_id', $employeeId)
                ->where('id', '!=', $shift->id)
                ->overlapping($startAt, $endAt)
                ->exists();

            if ($hasOverlap) {
                throw new \DomainException('Cannot assign shift: employee already has a shift during this time.');
            }

            // I5: Work location company check
            if (isset($data['work_location_id']) && $data['work_location_id'] !== null) {
                $locationExists = WorkLocation::withoutGlobalScopes()
                    ->where('id', $data['work_location_id'])
                    ->where('company_id', $shift->company_id)
                    ->exists();

                if (! $locationExists) {
                    throw new \DomainException('Work location does not belong to this company.');
                }
            }

            $before = $shift->only(['employee_id', 'date', 'start_at', 'end_at', 'work_location_id', 'notes']);

            $shift->fill(collect($data)->only([
                'employee_id', 'work_location_id', 'date', 'start_at', 'end_at',
                'timezone', 'notes', 'metadata',
            ])->toArray());

            $shift->save();

            app(AuditLogger::class)->logCompany(
                companyId: $shift->company_id,
                action: 'shift.updated',
                targetType: 'shift',
                targetId: (string) $shift->id,
                options: [
                    'diffBefore' => $before,
                    'diffAfter' => $shift->only(['employee_id', 'date', 'start_at', 'end_at', 'work_location_id', 'notes']),
                    'metadata' => ['category' => 'workforce.planning'],
                ],
            );

            return $shift;
        });
    }
}
