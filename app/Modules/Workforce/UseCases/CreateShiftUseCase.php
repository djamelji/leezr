<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WorkLocation;
use App\Modules\Workforce\Services\ScheduledActivityValidator;
use App\Modules\Workforce\Services\ShiftRuleValidator;
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
        ?string $overstaffingAck = null,
        ?string $overstaffingReason = null,
        ?int $actorId = null,
        bool $skipOverstaffing = false,
        ?array $snapshotOverride = null,
        bool $materializeComposition = false,
    ): Shift {
        return DB::transaction(function () use (
            $companyId, $employeeId, $date, $startAt, $endAt, $timezone,
            $scheduleTemplateId, $workLocationId, $notes, $overstaffingAck, $overstaffingReason, $actorId, $skipOverstaffing, $snapshotOverride, $materializeComposition
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

            // ADR-721 — COPIE fidèle : on emporte le snapshot SOURCE (horaires/pauses/catégorie figés),
            // indépendamment d'un éventuel changement/suppression du modèle depuis la création d'origine.
            if ($snapshotOverride !== null) {
                $templateSnapshot = $snapshotOverride;
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

            // LOT F: effective work-time rules (daily rest, daily/weekly max, night work).
            $breakMinutes = (int) ($templateSnapshot['break_duration_minutes'] ?? 0);
            $ruleCheck = app(ShiftRuleValidator::class)->validate(
                companyId: $companyId,
                employeeId: $employeeId,
                contract: $contract,
                startAt: $startAtCarbon,
                endAt: $endAtCarbon,
                date: $date,
                breakMinutes: $breakMinutes,
            );

            // ADR-719 — contrôle de SUREFFECTIF (après le réglementaire, dans la transaction = pas de
            // fenêtre de concurrence). Peut lever OverstaffingConfirmationRequired si confirmation absente/périmée.
            // ADR-720 — ignoré dans une opération groupée : le lot évalue le sureffectif CUMULÉ en amont.
            $skipOverstaffing || app(\App\Modules\Workforce\Services\OverstaffingGuard::class)->enforce($companyId, [
                'employee_id' => $employeeId, 'date' => $date,
                'start_at' => $startAtCarbon->toDateTimeString(), 'end_at' => $endAtCarbon->toDateTimeString(),
                'work_location_id' => $workLocationId, 'template_snapshot' => $templateSnapshot, 'ignore_shift_id' => null,
            ], $overstaffingAck, $overstaffingReason, $actorId);

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
                'metadata' => ['night_work' => $ruleCheck['night_work']],
            ]);

            if ($notes) {
                $shift->notes = $notes;
                $shift->save();
            }

            // M1 (ADR-724) — matérialisation des activités préconfigurées du modèle, DANS la transaction
            // (atomique : une activité invalide annule tout le shift). Jamais en copie (snapshotOverride =
            // CopyShiftsUseCase recopie les vraies ScheduledActivity) ni en duplication (sémantique préservée).
            if ($materializeComposition && $snapshotOverride === null
                && is_array($templateSnapshot) && ! empty($templateSnapshot['activities'])) {
                $this->materializeComposition($companyId, $shift, $templateSnapshot['activities']);
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

    /**
     * Matérialise les activités de la composition (offsets relatifs → instants absolus) en vraies
     * ScheduledActivity. Chaque activité est validée par ScheduledActivityValidator (bornes du shift,
     * chevauchement, pauses, capacité) — autorité unique, déjà déployée. Toute violation lève et annule
     * la transaction (pas de shift incomplet). source='template' pour tracer l'origine.
     *
     * @param  list<array<string,mixed>>  $activities
     */
    private function materializeComposition(int $companyId, Shift $shift, array $activities): void
    {
        $validator = app(ScheduledActivityValidator::class);

        foreach ($activities as $a) {
            $offset = (int) ($a['offset_minutes'] ?? 0);
            $duration = (int) ($a['duration_minutes'] ?? 0);
            if ($duration <= 0) {
                throw new \DomainException('Template activity has a non-positive duration.');
            }
            $start = $shift->start_at->copy()->addMinutes($offset);
            $end = $start->copy()->addMinutes($duration);

            $nonExclusive = (bool) ($a['non_exclusive'] ?? false);
            // Autorité : bornes du shift, pause, chevauchement, capacité (union). Atomique si throw.
            $validator->assertValid($shift, $start, $end, ['non_exclusive' => $nonExclusive]);

            $priority = $a['priority'] ?? 'normal';
            ScheduledActivity::create([
                'company_id' => $companyId,
                'shift_id' => $shift->id,
                'employee_id' => $shift->employee_id, // dérivé du shift (invariant)
                'schedule_template_id' => $shift->schedule_template_id,
                'work_location_id' => $a['work_location_id'] ?? $shift->work_location_id,
                'activity_type' => $a['activity_type'] ?? null,
                'coverage_category_id' => $a['coverage_category_id'] ?? null,
                'label' => $a['label'] ?? null,
                'start_at' => $start,
                'end_at' => $end,
                'status' => ScheduledActivity::STATUS_PLANNED,
                'priority' => in_array($priority, ScheduledActivity::PRIORITIES, true) ? $priority : 'normal',
                'source' => 'template',
                'metadata' => $nonExclusive ? ['non_exclusive' => true] : null,
            ]);
        }
    }
}
