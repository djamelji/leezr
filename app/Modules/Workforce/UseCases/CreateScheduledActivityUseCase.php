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
 * Crée une activité planifiée dans un Shift (Smart Planning SP1b, ADR-705 v2).
 *
 * employee_id/company_id STRICTEMENT dérivés du Shift (jamais saisis). Transactionnel + verrou sur le shift
 * (concurrence entre planificateurs). Shift publié autorisé = révision contrôlée (tracée), sans jamais muter
 * le Shift ni le TimeEntry. Validation intra-shift via ScheduledActivityValidator.
 *
 * @return array{activity: ScheduledActivity, warnings: list<string>}
 */
class CreateScheduledActivityUseCase
{
    public function __construct(private ScheduledActivityValidator $validator) {}

    public function execute(int $companyId, int $shiftId, array $data): array
    {
        return DB::transaction(function () use ($companyId, $shiftId, $data) {
            $shift = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($shiftId);

            if ($shift->status === Shift::STATUS_CANCELLED) {
                throw new \DomainException('Cannot add an activity to a cancelled shift.');
            }

            $startAt = Carbon::parse($data['start_at']);
            $endAt = Carbon::parse($data['end_at']);
            $locationId = $data['work_location_id'] ?? null;

            if ($locationId) {
                $ok = WorkLocation::withoutGlobalScopes()->where('id', $locationId)->where('company_id', $companyId)->exists();
                if (! $ok) {
                    throw new \DomainException('Work location does not belong to this company.');
                }
            }

            $this->validator->assertValid($shift, $startAt, $endAt, ['non_exclusive' => (bool) ($data['non_exclusive'] ?? false)]);
            $warnings = $this->validator->warnings($shift, $startAt, $endAt, $locationId);

            $activity = ScheduledActivity::create([
                'company_id' => $companyId,
                'shift_id' => $shift->id,
                'employee_id' => $shift->employee_id, // dérivé du shift
                'schedule_template_id' => $data['schedule_template_id'] ?? null,
                'work_location_id' => $locationId,
                'activity_type' => $data['activity_type'] ?? null,
                'coverage_category_id' => $data['coverage_category_id'] ?? null,
                'label' => $data['label'] ?? null,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => ScheduledActivity::STATUS_PLANNED,
                'priority' => $data['priority'] ?? 'normal',
                'source' => $data['source'] ?? 'manual',
                'notes' => $data['notes'] ?? null,
                'metadata' => ($data['non_exclusive'] ?? false) ? ['non_exclusive' => true] : null,
            ]);

            $onPublished = $shift->status === Shift::STATUS_PUBLISHED;
            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: $onPublished ? 'scheduled_activity.revised_add' : 'scheduled_activity.created',
                targetType: 'scheduled_activity',
                targetId: (string) $activity->id,
                options: ['metadata' => [
                    'category' => 'workforce.planning',
                    'shift_id' => $shift->id,
                    'revision' => $onPublished, // ajout sur shift publié = révision tracée
                ]],
            );

            // ADR-717 — intention de notification enregistrée DANS la transaction (durable) si le shift
            // est publié. Le contrôleur flush en post-commit (la commande planifiée rattrape).
            if ($onPublished) {
                $eventRef = 'activity:'.$activity->id.':'.optional($activity->updated_at)->timestamp;
                app(\App\Modules\Workforce\Services\PlanningOutbox::class)
                    ->enqueueChanged($companyId, [$shift->employee_id], \Illuminate\Support\Carbon::parse($startAt), $eventRef);
            }

            return [
                'activity' => $activity->fresh(),
                'warnings' => $warnings,
                // Pour notification post-commit (contrôleur) si modification d'un planning publié.
                'notify_employee_ids' => $onPublished ? [$shift->employee_id] : [],
                'notify_date' => $startAt,
            ];
        });
    }
}
