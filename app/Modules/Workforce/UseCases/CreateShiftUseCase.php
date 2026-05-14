<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WorkLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateShiftUseCase
{
    public function execute(
        int $companyId,
        int $employeeId,
        string $date,
        string $startAt,
        string $endAt,
        string $timezone,
        ?int $scheduleTemplateId = null,
        ?int $workLocationId = null,
        ?string $notes = null,
    ): Shift {
        return DB::transaction(function () use (
            $companyId, $employeeId, $date, $startAt, $endAt, $timezone,
            $scheduleTemplateId, $workLocationId, $notes
        ) {
            $startAtCarbon = Carbon::parse($startAt);
            $endAtCarbon = Carbon::parse($endAt);

            // I6: Cohérence temporelle
            if ($endAtCarbon->lte($startAtCarbon)) {
                throw new \DomainException('Shift end_at must be after start_at.');
            }

            // I3: Employee actif
            $employee = Employee::withoutGlobalScopes()
                ->where('id', $employeeId)
                ->where('company_id', $companyId)
                ->first();

            if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
                throw new \DomainException('Employee must be active to be assigned a shift.');
            }

            // I4: Contrat actif
            $contract = $employee->currentContract;
            if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
                throw new \DomainException('Employee must have an active contract.');
            }

            // I1: Pas de shift sur congé approved/consumed
            $hasLeave = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                ->where('date_from', '<=', $date)
                ->where('date_to', '>=', $date)
                ->exists();

            if ($hasLeave) {
                throw new \DomainException("Cannot assign shift: employee has approved leave on {$date}.");
            }

            // I2: Pas de double booking (datetime overlap)
            $hasOverlap = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->overlapping($startAtCarbon, $endAtCarbon)
                ->exists();

            if ($hasOverlap) {
                throw new \DomainException('Cannot assign shift: employee already has a shift during this time.');
            }

            // I5: Cross-company checks
            $templateSnapshot = null;
            if ($scheduleTemplateId) {
                $template = ScheduleTemplate::withoutGlobalScopes()
                    ->where('id', $scheduleTemplateId)
                    ->where('company_id', $companyId)
                    ->first();

                if (! $template) {
                    throw new \DomainException('Schedule template does not belong to this company.');
                }

                $templateSnapshot = $template->toSnapshot();
            }

            if ($workLocationId) {
                $locationExists = WorkLocation::withoutGlobalScopes()
                    ->where('id', $workLocationId)
                    ->where('company_id', $companyId)
                    ->exists();

                if (! $locationExists) {
                    throw new \DomainException('Work location does not belong to this company.');
                }
            }

            $shift = Shift::create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'schedule_template_id' => $scheduleTemplateId,
                'work_location_id' => $workLocationId,
                'template_snapshot' => $templateSnapshot,
                'date' => $date,
                'start_at' => $startAtCarbon,
                'end_at' => $endAtCarbon,
                'timezone' => $timezone,
                'status' => Shift::STATUS_DRAFT,
            ]);

            if ($notes) {
                $shift->notes = $notes;
                $shift->save();
            }

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'shift.created',
                targetType: 'shift',
                targetId: (string) $shift->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.planning',
                        'employee_id' => $employeeId,
                        'date' => $date,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                    ],
                ],
            );

            return $shift;
        });
    }
}
