<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WorkLocation;
use App\Modules\Workforce\Services\ScheduledActivityValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modifie / réaffecte une activité planifiée (Smart Planning SP1b, ADR-705 v2).
 *
 * Déplacement temporel, durée, lieu, type, priorité, notes ; réaffectation = changement de shift cible
 * (employee_id re-dérivé du shift cible). Verrou optimiste (expected_updated_at). Sur shift publié =
 * révision contrôlée tracée (old/new). Ne mute jamais le Shift ni le TimeEntry.
 *
 * @return array{activity: ScheduledActivity, warnings: list<string>}
 */
class UpdateScheduledActivityUseCase
{
    public function __construct(private ScheduledActivityValidator $validator) {}

    public function execute(ScheduledActivity $activity, array $data): array
    {
        return DB::transaction(function () use ($activity, $data) {
            $companyId = $activity->company_id;

            // Réaffectation éventuelle vers un autre shift (même entreprise).
            $targetShiftId = $data['shift_id'] ?? $activity->shift_id;
            $shift = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($targetShiftId);

            if ($shift->status === Shift::STATUS_CANCELLED) {
                throw new \DomainException('Cannot move an activity to a cancelled shift.');
            }

            $startAt = isset($data['start_at']) ? Carbon::parse($data['start_at']) : $activity->start_at;
            $endAt = isset($data['end_at']) ? Carbon::parse($data['end_at']) : $activity->end_at;
            $locationId = array_key_exists('work_location_id', $data) ? $data['work_location_id'] : $activity->work_location_id;
            $nonExclusive = array_key_exists('non_exclusive', $data)
                ? (bool) $data['non_exclusive']
                : (bool) ($activity->metadata['non_exclusive'] ?? false);

            if ($locationId) {
                $ok = WorkLocation::withoutGlobalScopes()->where('id', $locationId)->where('company_id', $companyId)->exists();
                if (! $ok) {
                    throw new \DomainException('Work location does not belong to this company.');
                }
            }

            $this->validator->assertValid($shift, $startAt, $endAt, ['non_exclusive' => $nonExclusive], $activity->id);
            $warnings = $this->validator->warnings($shift, $startAt, $endAt, $locationId, $activity->id);

            $before = $activity->only(['shift_id', 'start_at', 'end_at', 'work_location_id', 'activity_type', 'priority']);

            $metadata = $activity->metadata ?? [];
            if (array_key_exists('non_exclusive', $data)) {
                if ($nonExclusive) {
                    $metadata['non_exclusive'] = true;
                } else {
                    unset($metadata['non_exclusive']);
                }
            }

            $activity->fill([
                'shift_id' => $shift->id,
                'employee_id' => $shift->employee_id, // re-dérivé du shift cible
                'start_at' => $startAt,
                'end_at' => $endAt,
                'work_location_id' => $locationId,
                'activity_type' => $data['activity_type'] ?? $activity->activity_type,
                'label' => $data['label'] ?? $activity->label,
                'priority' => $data['priority'] ?? $activity->priority,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $activity->notes,
                'metadata' => $metadata ?: null,
            ]);
            $activity->save();

            $onPublished = $shift->status === Shift::STATUS_PUBLISHED;
            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: $onPublished ? 'scheduled_activity.revised' : 'scheduled_activity.updated',
                targetType: 'scheduled_activity',
                targetId: (string) $activity->id,
                options: [
                    'diffBefore' => $before,
                    'diffAfter' => $activity->only(['shift_id', 'start_at', 'end_at', 'work_location_id', 'activity_type', 'priority']),
                    'metadata' => ['category' => 'workforce.planning', 'revision' => $onPublished],
                ],
            );

            return ['activity' => $activity->fresh(), 'warnings' => $warnings];
        });
    }
}
