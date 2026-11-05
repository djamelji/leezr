<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired;
use App\Modules\Workforce\Services\OverstaffingEvaluator;
use App\Modules\Workforce\Services\OverstaffingPolicy;
use App\Modules\Workforce\Services\ShiftRuleValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copie de SHIFTS COMPLETS (Smart Planning Lot 4B.3-A, ADR-721).
 *
 * Copie fidèle : snapshot SOURCE (horaires/pauses/catégorie figés), ScheduledActivities (décalées à la
 * nouvelle date en préservant leurs positions relatives, nuit incluse), site/Department. Les créations
 * sont des BROUILLONS INDÉPENDANTS. Ne recopie JAMAIS : id source, statut publié, historique de
 * publication, verrou, relation de remplacement, notifications, pointages.
 *
 * Réutilise CreateShiftUseCase (autorité par cellule + cumul réglementaire dans la transaction) ; le
 * sureffactif est CUMULATIF (OverstaffingEvaluator::evaluateMany, ADR-719/720). Atomicité tout-ou-rien
 * sur l'ensemble APPLICABLE : une erreur de création (shift OU activité) annule l'intégralité du lot —
 * jamais de shift « copié » incomplet. Les cellules exclues sont listées avec motif, jamais créées.
 */
class CopyShiftsUseCase
{
    private const MAX_OPS = 500;

    const BLOCK_INVALID_SOURCE = 'invalid_source';
    const BLOCK_INACTIVE = 'inactive';
    const BLOCK_NO_CONTRACT = 'no_contract';
    const BLOCK_OUT_OF_SCOPE = 'out_of_scope';
    const BLOCK_ABSENT = 'absent';
    const BLOCK_OVERLAP = 'overlap';
    const BLOCK_PUBLISHED_COLLISION = 'published_collision';
    const BLOCK_REGULATORY = 'regulatory';

    /**
     * @param  array<int, array{source_shift_id:int, target_employee_id:?int, target_date:string}>  $ops
     * @param  array<int>|null  $visibleIds
     */
    public function preview(int $companyId, array $ops, ?array $visibleIds = null): array
    {
        [$classified, $prospects] = $this->classify($companyId, $ops, $visibleIds);
        $overstaffing = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $prospects);

        $public = array_map(fn ($c) => collect($c)->except(['_source', '_delta'])->all(), $classified);

        return [
            'operations' => $public,
            'applicable_count' => count($prospects),
            'blocked_count' => count($classified) - count($prospects),
            'overstaffing' => $overstaffing,
            'mode' => app(OverstaffingPolicy::class)->mode($companyId),
        ];
    }

    public function commit(int $companyId, array $ops, ?array $visibleIds, ?string $ack, ?string $reason, ?int $actorId): array
    {
        if (count($ops) > self::MAX_OPS) {
            throw new \DomainException('Copy limit exceeded ('.count($ops).' > '.self::MAX_OPS.').');
        }

        return DB::transaction(function () use ($companyId, $ops, $visibleIds, $ack, $reason, $actorId) {
            [$classified, $prospects] = $this->classify($companyId, $ops, $visibleIds);

            // SUREFFECTIF CUMULATIF sur tout le lot (activités/catégories incluses via le snapshot source).
            $eval = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $prospects);
            if ($eval['overstaffed']) {
                $policy = app(OverstaffingPolicy::class);
                $mode = $policy->mode($companyId);
                if ($policy->requiresConfirmation($mode)) {
                    $needsReason = $policy->requiresReason($mode);
                    if (! ($ack !== null && hash_equals($eval['signature'], $ack)) || ($needsReason && trim((string) $reason) === '')) {
                        throw new OverstaffingConfirmationRequired($eval['impacts'], $eval['signature'], $mode, $needsReason);
                    }
                }
            }

            $created = [];
            foreach ($classified as $c) {
                if ($c['status'] !== 'applicable') {
                    continue;
                }
                /** @var Shift $source */
                $source = $c['_source'];
                $delta = $c['_delta'];

                // 1) Nouveau brouillon : snapshot SOURCE figé (pauses/catégorie), horaires décalés (nuit incluse).
                $new = app(CreateShiftUseCase::class)->execute(
                    companyId: $companyId,
                    employeeId: $c['target_employee_id'],
                    date: $c['target_date'],
                    startAt: $source->start_at->copy()->addDays($delta)->toDateTimeString(),
                    endAt: $source->end_at->copy()->addDays($delta)->toDateTimeString(),
                    timezone: $source->timezone,
                    scheduleTemplateId: $source->schedule_template_id,
                    workLocationId: $source->work_location_id,
                    notes: $source->notes,
                    skipOverstaffing: true,
                    snapshotOverride: $source->template_snapshot ?? [],
                );

                // 2) Clone des ScheduledActivities PLANIFIÉES (décalage identique → positions relatives + nuit).
                foreach ($source->scheduledActivities()->where('status', ScheduledActivity::STATUS_PLANNED)->get() as $act) {
                    ScheduledActivity::create([
                        'company_id' => $companyId,
                        'shift_id' => $new->id,
                        'employee_id' => $c['target_employee_id'],
                        'schedule_template_id' => $act->schedule_template_id,
                        'work_location_id' => $act->work_location_id,
                        'activity_type' => $act->activity_type,
                        'coverage_category_id' => $act->coverage_category_id,
                        'label' => $act->label,
                        'start_at' => $act->start_at->copy()->addDays($delta),
                        'end_at' => $act->end_at->copy()->addDays($delta),
                        'status' => ScheduledActivity::STATUS_PLANNED,
                        'priority' => $act->priority,
                        'source' => 'copy',
                        'notes' => $act->notes,
                        'subject_type' => $act->subject_type,
                        'subject_id' => $act->subject_id,
                        'metadata' => $act->metadata,
                    ]);
                }

                $created[] = $new->id;
            }

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'shifts.copied',
                targetType: 'shift',
                targetId: 'copy',
                options: ['metadata' => [
                    'category' => 'workforce.planning',
                    'created_count' => count($created),
                    'blocked_count' => count($classified) - count($created),
                    'overstaffing_confirmed' => $eval['overstaffed'],
                    'actor_id' => $actorId,
                ]],
            );

            return [
                'created' => $created,
                'created_count' => count($created),
                'blocked' => collect($classified)->where('status', 'blocked')
                    ->map(fn ($c) => ['source_shift_id' => $c['source_shift_id'], 'target_employee_id' => $c['target_employee_id'], 'target_date' => $c['target_date'], 'reason' => $c['reason']])
                    ->values()->all(),
            ];
        });
    }

    /**
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     */
    private function classify(int $companyId, array $ops, ?array $visibleIds): array
    {
        $classified = [];
        $prospects = [];

        foreach ($ops as $op) {
            $source = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('id', (int) $op['source_shift_id'])->first();
            $targetDate = substr($op['target_date'], 0, 10);
            $base = [
                'source_shift_id' => (int) $op['source_shift_id'],
                'target_employee_id' => (int) ($op['target_employee_id'] ?? ($source->employee_id ?? 0)),
                'target_date' => $targetDate,
            ];

            if (! $source) {
                $classified[] = $base + ['status' => 'blocked', 'reason' => self::BLOCK_INVALID_SOURCE];

                continue;
            }
            $employeeId = $base['target_employee_id'];
            $delta = Carbon::parse($source->date->toDateString())->diffInDays(Carbon::parse($targetDate), false);
            $start = $source->start_at->copy()->addDays($delta);
            $end = $source->end_at->copy()->addDays($delta);

            if ($visibleIds !== null && ! in_array($employeeId, $visibleIds, true)) {
                $classified[] = $base + ['status' => 'blocked', 'reason' => self::BLOCK_OUT_OF_SCOPE];

                continue;
            }
            $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $employeeId)->first();
            if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
                $classified[] = $base + ['status' => 'blocked', 'reason' => self::BLOCK_INACTIVE];

                continue;
            }
            $contract = $employee->currentContract;
            if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
                $classified[] = $base + ['status' => 'blocked', 'reason' => self::BLOCK_NO_CONTRACT];

                continue;
            }
            $absent = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                ->whereDate('date_from', '<=', $targetDate)->whereDate('date_to', '>=', $targetDate)->exists();
            if ($absent) {
                $classified[] = $base + ['status' => 'blocked', 'reason' => self::BLOCK_ABSENT];

                continue;
            }
            // Chevauchement sur la cible (on NE touche jamais l'existant ; un publié chevauchant = collision signalée).
            $conflict = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('employee_id', $employeeId)->notCancelled()
                ->overlapping($start, $end)->first();
            if ($conflict) {
                $reason = $conflict->status === Shift::STATUS_DRAFT ? self::BLOCK_OVERLAP : self::BLOCK_PUBLISHED_COLLISION;
                $classified[] = $base + ['status' => 'blocked', 'reason' => $reason];

                continue;
            }
            try {
                app(ShiftRuleValidator::class)->validate(
                    companyId: $companyId, employeeId: $employeeId, contract: $contract,
                    startAt: $start, endAt: $end, date: $targetDate,
                    breakMinutes: (int) ($source->template_snapshot['break_duration_minutes'] ?? 0),
                );
            } catch (\DomainException $e) {
                $classified[] = $base + ['status' => 'blocked', 'reason' => self::BLOCK_REGULATORY, 'detail' => $e->getMessage()];

                continue;
            }

            $classified[] = $base + ['status' => 'applicable', 'reason' => null, '_source' => $source, '_delta' => $delta];
            $prospects[] = [
                'employee_id' => $employeeId, 'date' => $targetDate,
                'start_at' => $start->toDateTimeString(), 'end_at' => $end->toDateTimeString(),
                'work_location_id' => $source->work_location_id,
                'template_snapshot' => $source->template_snapshot ?? [], 'ignore_shift_id' => null,
            ];
        }

        // Nettoyage des clés internes pour la sortie preview (on garde _source/_delta seulement en commit).
        return [$classified, $prospects];
    }
}
