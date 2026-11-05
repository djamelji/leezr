<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired;
use App\Modules\Workforce\Services\OverstaffingEvaluator;
use App\Modules\Workforce\Services\OverstaffingGuard;
use App\Modules\Workforce\Services\OverstaffingPolicy;
use App\Modules\Workforce\Services\ShiftRuleValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Application GROUPÉE d'un modèle à plusieurs cellules (salarié×jour) — Smart Planning Lot 4B.2 (ADR-720).
 *
 * Orchestration métier unique (PAS N appels front indépendants). Réutilise les UseCases d'écriture
 * existants : chaque cellule applicable est créée via CreateShiftUseCase (autorité par cellule : actif,
 * contrat, congé, chevauchement, règles temps de travail — cumulées dans la transaction). Le SUREFFECTIF
 * est évalué CUMULATIVEMENT (ADR-719, OverstaffingEvaluator::evaluateMany) sur l'ensemble du lot et exige
 * une confirmation serveur si la politique l'impose (signature HMAC → re-confirmation si l'état change).
 *
 * Atomicité : la création porte sur l'ensemble APPLICABLE en TOUT-OU-RIEN (toute exception d'une cellule
 * remonte et annule l'intégralité du lot). Les cellules BLOQUÉES (motif) sont rapportées, jamais créées
 * silencieusement — l'utilisateur les a vues en prévisualisation.
 */
class BulkApplyTemplateUseCase
{
    private const MAX_CELLS = 500;

    const BLOCK_INVALID_TEMPLATE = 'invalid_template';
    const BLOCK_INACTIVE = 'inactive';
    const BLOCK_NO_CONTRACT = 'no_contract';
    const BLOCK_OUT_OF_SCOPE = 'out_of_scope';
    const BLOCK_ABSENT = 'absent';
    const BLOCK_OVERLAP = 'overlap';
    const BLOCK_REGULATORY = 'regulatory';

    /**
     * Prévisualisation (lecture seule) : classe chaque cellule + sureffectif cumulatif + signature.
     *
     * @param  array<int, array{employee_id:int, date:string}>  $cells
     * @param  array<int>|null  $visibleIds  scope manager (null = vue globale)
     */
    public function preview(int $companyId, int $templateId, array $cells, ?array $visibleIds = null): array
    {
        $template = $this->loadTemplate($companyId, $templateId);
        if (! $template) {
            return [
                'template_id' => $templateId,
                'cells' => array_map(fn ($c) => $this->cell($c, 'blocked', self::BLOCK_INVALID_TEMPLATE), $cells),
                'applicable_count' => 0,
                'blocked_count' => count($cells),
                'overstaffing' => ['overstaffed' => false, 'impacts' => [], 'signature' => app(OverstaffingEvaluator::class)->sign($companyId, [])],
                'mode' => app(OverstaffingPolicy::class)->mode($companyId),
            ];
        }

        [$classified, $prospects] = $this->classify($companyId, $template, $cells, $visibleIds);
        $overstaffing = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $prospects);

        return [
            'template_id' => $templateId,
            'template_name' => $template->name,
            'cells' => $classified,
            'applicable_count' => count($prospects),
            'blocked_count' => count($cells) - count($prospects),
            'overstaffing' => $overstaffing,
            'mode' => app(OverstaffingPolicy::class)->mode($companyId),
        ];
    }

    /**
     * Commit atomique : crée les cellules applicables en tout-ou-rien, sureffectif cumulatif confirmé.
     *
     * @param  array<int, array{employee_id:int, date:string}>  $cells
     * @param  array<int>|null  $visibleIds
     */
    public function commit(int $companyId, int $templateId, array $cells, ?array $visibleIds, ?string $ack, ?string $reason, ?int $actorId): array
    {
        if (count($cells) > self::MAX_CELLS) {
            throw new \DomainException('Bulk limit exceeded ('.count($cells).' > '.self::MAX_CELLS.').');
        }
        $template = $this->loadTemplate($companyId, $templateId);
        if (! $template) {
            throw new \DomainException('Schedule template not found or does not belong to this company.');
        }

        return DB::transaction(function () use ($companyId, $template, $templateId, $cells, $visibleIds, $ack, $reason, $actorId) {
            // Re-classification DANS la transaction (état committé courant).
            [$classified, $prospects] = $this->classify($companyId, $template, $cells, $visibleIds);

            // SUREFFECTIF CUMULATIF : évalué sur l'ensemble applicable ; confirmation serveur selon politique.
            // Signature périmée (couverture changée entre preview et commit) → re-confirmation exigée.
            $eval = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $prospects);
            if ($eval['overstaffed']) {
                $policy = app(OverstaffingPolicy::class);
                $mode = $policy->mode($companyId);
                if ($policy->requiresConfirmation($mode)) {
                    $needsReason = $policy->requiresReason($mode);
                    $ackValid = $ack !== null && hash_equals($eval['signature'], $ack);
                    if (! $ackValid || ($needsReason && trim((string) $reason) === '')) {
                        throw new OverstaffingConfirmationRequired($eval['impacts'], $eval['signature'], $mode, $needsReason);
                    }
                }
                app(AuditLogger::class)->logCompany(
                    companyId: $companyId,
                    action: 'shifts.bulk_overstaffing_confirmed',
                    targetType: 'shift',
                    targetId: 'bulk',
                    options: ['metadata' => ['category' => 'workforce.planning', 'impacts' => $eval['impacts'], 'reason' => $reason, 'actor_id' => $actorId]],
                );
            }

            // Création TOUT-OU-RIEN des cellules applicables (toute exception annule le lot entier).
            $created = [];
            foreach ($classified as $c) {
                if ($c['status'] !== 'applicable') {
                    continue;
                }
                $created[] = app(CreateShiftUseCase::class)->execute(
                    companyId: $companyId,
                    employeeId: $c['employee_id'],
                    date: $c['date'],
                    startAt: $c['date'].' '.substr($template->start_time, 0, 5).':00',
                    endAt: $this->endDateTime($c['date'], $template),
                    timezone: 'Europe/Paris',
                    scheduleTemplateId: $template->id,
                    workLocationId: $template->work_location_id,
                    skipOverstaffing: true, // sureffectif déjà traité cumulativement
                );
            }

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'shifts.bulk_applied',
                targetType: 'shift',
                targetId: 'bulk',
                options: ['metadata' => [
                    'category' => 'workforce.planning',
                    'template_id' => $templateId,
                    'created_count' => count($created),
                    'blocked_count' => count($cells) - count($created),
                    'actor_id' => $actorId,
                ]],
            );

            return [
                'created' => collect($created)->map(fn ($s) => $s->id)->all(),
                'created_count' => count($created),
                'blocked' => collect($classified)->where('status', 'blocked')->values()->all(),
                'cells' => $classified,
            ];
        });
    }

    private function loadTemplate(int $companyId, int $templateId): ?ScheduleTemplate
    {
        return ScheduleTemplate::withoutGlobalScopes()
            ->where('id', $templateId)->where('company_id', $companyId)
            ->where('enabled', true)->first();
    }

    /**
     * Classe chaque cellule (lecture) ; renvoie [cellules annotées, prospects applicables pour le sureffectif].
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     */
    private function classify(int $companyId, ScheduleTemplate $template, array $cells, ?array $visibleIds): array
    {
        $snapshot = $template->toSnapshot();
        $classified = [];
        $prospects = [];

        foreach ($cells as $cell) {
            $employeeId = (int) $cell['employee_id'];
            $date = substr($cell['date'], 0, 10);
            $start = $date.' '.substr($template->start_time, 0, 5).':00';
            $end = $this->endDateTime($date, $template);

            if ($visibleIds !== null && ! in_array($employeeId, $visibleIds, true)) {
                $classified[] = $this->cell($cell, 'blocked', self::BLOCK_OUT_OF_SCOPE);

                continue;
            }

            $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $employeeId)->first();
            if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
                $classified[] = $this->cell($cell, 'blocked', self::BLOCK_INACTIVE);

                continue;
            }
            $contract = $employee->currentContract;
            if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
                $classified[] = $this->cell($cell, 'blocked', self::BLOCK_NO_CONTRACT);

                continue;
            }
            // Congé confirmé couvrant la date (motif JAMAIS exposé).
            $absent = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
                ->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)->exists();
            if ($absent) {
                $classified[] = $this->cell($cell, 'blocked', self::BLOCK_ABSENT);

                continue;
            }
            // Chevauchement avec un shift existant (une cellule occupée par un créneau DISJOINT reste applicable).
            $overlap = Shift::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('employee_id', $employeeId)->notCancelled()
                ->overlapping(Carbon::parse($start), Carbon::parse($end))->exists();
            if ($overlap) {
                $classified[] = $this->cell($cell, 'blocked', self::BLOCK_OVERLAP);

                continue;
            }
            // Règles réglementaires (best-effort en preview ; autorité = commit, cumul dans la transaction).
            try {
                app(ShiftRuleValidator::class)->validate(
                    companyId: $companyId, employeeId: $employeeId, contract: $contract,
                    startAt: Carbon::parse($start), endAt: Carbon::parse($end), date: $date,
                    breakMinutes: (int) ($snapshot['break_duration_minutes'] ?? 0),
                );
            } catch (\DomainException $e) {
                $classified[] = $this->cell($cell, 'blocked', self::BLOCK_REGULATORY, $e->getMessage());

                continue;
            }

            $classified[] = $this->cell($cell, 'applicable', null);
            $prospects[] = [
                'employee_id' => $employeeId, 'date' => $date,
                'start_at' => $start, 'end_at' => $end,
                'work_location_id' => $template->work_location_id, 'template_snapshot' => $snapshot, 'ignore_shift_id' => null,
            ];
        }

        return [$classified, $prospects];
    }

    private function endDateTime(string $date, ScheduleTemplate $template): string
    {
        $start = Carbon::parse($date.' '.substr($template->start_time, 0, 5));
        $end = Carbon::parse($date.' '.substr($template->end_time, 0, 5));
        if ($end->lte($start)) {
            $end->addDay(); // shift de nuit
        }

        return $end->toDateTimeString();
    }

    private function cell(array $cell, string $status, ?string $reason, ?string $detail = null): array
    {
        return [
            'employee_id' => (int) $cell['employee_id'],
            'date' => substr($cell['date'], 0, 10),
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }
}
