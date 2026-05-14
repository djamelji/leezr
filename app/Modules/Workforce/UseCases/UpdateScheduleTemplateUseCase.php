<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\WorkLocation;

class UpdateScheduleTemplateUseCase
{
    public function execute(ScheduleTemplate $template, array $data): ScheduleTemplate
    {
        if (isset($data['name']) && $data['name'] !== $template->name) {
            $exists = ScheduleTemplate::withoutGlobalScopes()
                ->where('company_id', $template->company_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $template->id)
                ->exists();

            if ($exists) {
                throw new \DomainException("A schedule template named '{$data['name']}' already exists.");
            }
        }

        if (isset($data['work_location_id']) && $data['work_location_id'] !== null) {
            $location = WorkLocation::withoutGlobalScopes()
                ->where('id', $data['work_location_id'])
                ->where('company_id', $template->company_id)
                ->first();

            if (! $location) {
                throw new \DomainException('Work location does not belong to this company.');
            }
        }

        $before = $template->only(['name', 'start_time', 'end_time', 'break_duration_minutes', 'enabled']);

        $template->fill(collect($data)->only([
            'name', 'start_time', 'end_time', 'break_duration_minutes',
            'color', 'work_location_id', 'enabled', 'sort_order', 'metadata',
        ])->toArray());

        $template->save();

        app(AuditLogger::class)->logCompany(
            companyId: $template->company_id,
            action: 'schedule_template.updated',
            targetType: 'schedule_template',
            targetId: (string) $template->id,
            options: [
                'diffBefore' => $before,
                'diffAfter' => $template->only(['name', 'start_time', 'end_time', 'break_duration_minutes', 'enabled']),
                'metadata' => ['category' => 'workforce.planning'],
            ],
        );

        return $template;
    }
}
