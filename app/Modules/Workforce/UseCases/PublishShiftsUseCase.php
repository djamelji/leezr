<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublishShiftsUseCase
{
    /**
     * Publish a batch of draft shifts atomically.
     * All shifts must pass validation or none are published (I8).
     *
     * @param  array<int>  $shiftIds
     * @return Collection<Shift>
     */
    public function execute(int $companyId, array $shiftIds): Collection
    {
        return DB::transaction(function () use ($companyId, $shiftIds) {
            // Lock shifts for update (CORR #6)
            $shifts = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', $shiftIds)
                ->lockForUpdate()
                ->get();

            if ($shifts->count() !== count($shiftIds)) {
                throw new \DomainException('Some shifts were not found or do not belong to this company.');
            }

            $now = now();

            // Validate each shift
            foreach ($shifts as $shift) {
                // I7: Must be draft
                if ($shift->status !== Shift::STATUS_DRAFT) {
                    throw new \DomainException("Shift #{$shift->id} is not in draft status (current: {$shift->status}).");
                }

                // I3: Employee active
                $employee = Employee::withoutGlobalScopes()
                    ->where('id', $shift->employee_id)
                    ->where('company_id', $companyId)
                    ->first();

                if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
                    throw new \DomainException("Shift #{$shift->id}: employee is not active.");
                }

                // I4: Contract active
                $contract = $employee->currentContract;
                if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
                    throw new \DomainException("Shift #{$shift->id}: employee has no active contract.");
                }

                // I1: No leave overlap
                $dateString = $shift->date->toDateString();
                $hasLeave = LeaveRequest::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $shift->employee_id)
                    ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                    ->where('date_from', '<=', $dateString)
                    ->where('date_to', '>=', $dateString)
                    ->exists();

                if ($hasLeave) {
                    throw new \DomainException("Shift #{$shift->id}: employee has approved leave on {$dateString}.");
                }

                // I2: No double booking (check against non-batch shifts + within batch)
                $hasOverlap = Shift::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $shift->employee_id)
                    ->where('id', '!=', $shift->id)
                    ->whereNotIn('id', $shiftIds)
                    ->overlapping($shift->start_at, $shift->end_at)
                    ->exists();

                if ($hasOverlap) {
                    throw new \DomainException("Shift #{$shift->id}: employee already has a shift during this time.");
                }
            }

            // Check for intra-batch overlaps (same employee, overlapping times within the batch)
            $byEmployee = $shifts->groupBy('employee_id');
            foreach ($byEmployee as $employeeId => $employeeShifts) {
                $sorted = $employeeShifts->sortBy('start_at')->values();
                for ($i = 0; $i < $sorted->count() - 1; $i++) {
                    for ($j = $i + 1; $j < $sorted->count(); $j++) {
                        if ($sorted[$i]->start_at < $sorted[$j]->end_at && $sorted[$i]->end_at > $sorted[$j]->start_at) {
                            throw new \DomainException(
                                "Shifts #{$sorted[$i]->id} and #{$sorted[$j]->id} overlap for the same employee."
                            );
                        }
                    }
                }
            }

            // All validated — publish all
            foreach ($shifts as $shift) {
                $shift->transitionTo(Shift::STATUS_PUBLISHED);
                $shift->published_at = $now;
                $shift->save();
            }

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'shifts.published',
                targetType: 'shift',
                targetId: implode(',', $shiftIds),
                options: [
                    'metadata' => [
                        'category' => 'workforce.planning',
                        'shift_count' => count($shiftIds),
                        'shift_ids' => $shiftIds,
                    ],
                ],
            );

            return $shifts;
        });
    }
}
