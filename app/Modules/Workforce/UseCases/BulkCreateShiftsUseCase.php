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
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkCreateShiftsUseCase
{
    private const DEFAULT_BULK_LIMIT = 500;

    /**
     * Create multiple shifts from a template for multiple employees over a date range.
     *
     * @param  array<int>  $employeeIds
     */
    public function execute(
        int $companyId,
        int $scheduleTemplateId,
        array $employeeIds,
        string $dateFrom,
        string $dateTo,
        ?int $workLocationId = null,
        int $bulkLimit = self::DEFAULT_BULK_LIMIT,
    ): Collection {
        $template = ScheduleTemplate::withoutGlobalScopes()
            ->where('id', $scheduleTemplateId)
            ->where('company_id', $companyId)
            ->first();

        if (! $template) {
            throw new \DomainException('Schedule template not found or does not belong to this company.');
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

        // Resolve timezone: location → company
        $timezone = null;
        if ($workLocationId) {
            $location = WorkLocation::withoutGlobalScopes()->find($workLocationId);
            $timezone = $location?->timezone;
        }
        if (! $timezone) {
            $company = \App\Platform\Models\Company::find($companyId);
            $timezone = $company?->timezone ?? config('app.timezone');
        }

        $dates = CarbonPeriod::create($dateFrom, $dateTo)->toArray();
        $totalShifts = count($employeeIds) * count($dates);

        // CORR #7: Bulk limit controlled by entitlement
        if ($totalShifts > $bulkLimit) {
            throw new \DomainException("Bulk create limit exceeded: {$totalShifts} shifts requested, maximum is {$bulkLimit}.");
        }

        $templateSnapshot = $template->toSnapshot();

        return DB::transaction(function () use (
            $companyId, $employeeIds, $dates, $template, $templateSnapshot,
            $workLocationId, $timezone
        ) {
            $created = collect();

            foreach ($employeeIds as $employeeId) {
                // I3 + I4: Employee active + contract active
                $employee = Employee::withoutGlobalScopes()
                    ->where('id', $employeeId)
                    ->where('company_id', $companyId)
                    ->first();

                if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
                    throw new \DomainException("Employee #{$employeeId} is not active.");
                }

                $contract = $employee->currentContract;
                if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
                    throw new \DomainException("Employee #{$employeeId} has no active contract.");
                }

                foreach ($dates as $dateCarbon) {
                    $dateString = $dateCarbon->toDateString();

                    // Compute start_at/end_at in UTC from template times + date + timezone
                    $startAt = Carbon::parse("{$dateString} {$template->start_time}", $timezone)->utc();
                    $endAt = Carbon::parse("{$dateString} {$template->end_time}", $timezone)->utc();

                    // Handle overnight shifts (end_time < start_time means next day)
                    if ($endAt->lte($startAt)) {
                        $endAt->addDay();
                    }

                    // I1: Leave check
                    $hasLeave = LeaveRequest::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->where('employee_id', $employeeId)
                        ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                        ->where('date_from', '<=', $dateString)
                        ->where('date_to', '>=', $dateString)
                        ->exists();

                    if ($hasLeave) {
                        throw new \DomainException("Employee #{$employeeId} has approved leave on {$dateString}.");
                    }

                    // I2: Double booking
                    $hasOverlap = Shift::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->where('employee_id', $employeeId)
                        ->overlapping($startAt, $endAt)
                        ->exists();

                    if ($hasOverlap) {
                        throw new \DomainException("Employee #{$employeeId} already has a shift overlapping on {$dateString}.");
                    }

                    $shift = Shift::create([
                        'company_id' => $companyId,
                        'employee_id' => $employeeId,
                        'schedule_template_id' => $template->id,
                        'work_location_id' => $workLocationId,
                        'template_snapshot' => $templateSnapshot,
                        'date' => $dateString,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'timezone' => $timezone,
                        'status' => Shift::STATUS_DRAFT,
                    ]);

                    $created->push($shift);
                }
            }

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'shifts.bulk_created',
                targetType: 'shift',
                targetId: $created->pluck('id')->implode(','),
                options: [
                    'metadata' => [
                        'category' => 'workforce.planning',
                        'template_id' => $template->id,
                        'employee_count' => count($employeeIds),
                        'shift_count' => $created->count(),
                    ],
                ],
            );

            return $created;
        });
    }
}
