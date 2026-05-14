<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\WorkLocation;

class CreateScheduleTemplateUseCase
{
    public function execute(
        int $companyId,
        string $name,
        string $startTime,
        string $endTime,
        int $breakDurationMinutes = 0,
        ?string $color = null,
        ?int $workLocationId = null,
    ): ScheduleTemplate {
        $exists = ScheduleTemplate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new \DomainException("A schedule template named '{$name}' already exists.");
        }

        if ($workLocationId) {
            $location = WorkLocation::withoutGlobalScopes()
                ->where('id', $workLocationId)
                ->where('company_id', $companyId)
                ->first();

            if (! $location) {
                throw new \DomainException('Work location does not belong to this company.');
            }
        }

        $template = ScheduleTemplate::create([
            'company_id' => $companyId,
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_duration_minutes' => $breakDurationMinutes,
            'color' => $color,
            'work_location_id' => $workLocationId,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'schedule_template.created',
            targetType: 'schedule_template',
            targetId: (string) $template->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.planning',
                    'name' => $name,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ],
            ],
        );

        return $template;
    }
}
