<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WeekTemplate;
use App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired;
use App\Modules\Workforce\Services\OverstaffingEvaluator;
use App\Modules\Workforce\Services\OverstaffingPolicy;
use App\Modules\Workforce\Services\ShiftRuleValidator;
use App\Modules\Workforce\Support\WeekStartResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrateur M3 (ADR-726) — PRÉVISUALISE puis APPLIQUE une semaine type à une semaine réelle.
 *
 * N'invente PAS de salarié/autorisation/disponibilité. Réutilise les opérations/validateurs métier autorisés
 * (CreateShiftUseCase → matérialise activités+pauses M1 + réglementaire + contrat/absence/chevauchement ;
 * OverstaffingEvaluator cumulatif). Ne détourne PAS CopyShiftsUseCase (qui copie des shifts réels). N'est
 * PAS une 2e source de vérité des CoverageRequirement (les besoins exprimés ≠ besoins opérationnels).
 *
 * Nominative : un salarié désigné → matérialisé si autorisé, jamais remplacé en silence.
 * Par besoins : des créneaux à pourvoir → candidats éligibles proposés, le manager confirme ; un créneau non
 * attribué reste NON POURVU (jamais un Shift). Mixte : les deux dans un seul processus et un seul bilan.
 *
 * Application ATOMIQUE (tout-ou-rien sur l'ensemble applicable), revalidée au commit (le preview n'autorise
 * pas), enregistrée comme UNE opération réversible (apply_week_template). Aucune publication automatique.
 */
class ApplyWeekTemplateUseCase
{
    private const MAX_CELLS = 500;

    public const BLOCK_INVALID_TEMPLATE = 'invalid_template';
    public const BLOCK_INVALID_EMPLOYEE = 'invalid_employee';
    public const BLOCK_INACTIVE = 'inactive';
    public const BLOCK_NO_CONTRACT = 'no_contract';
    public const BLOCK_OUT_OF_SCOPE = 'out_of_scope';
    public const BLOCK_ABSENT = 'absent';
    public const BLOCK_OVERLAP = 'overlap';
    public const BLOCK_REGULATORY = 'regulatory';
    public const BLOCK_CONTRACT_EXPIRED = 'contract_expired';

    /** Premier jour de la semaine CONTENANT $anyDate, selon le premier jour de semaine Company (jamais lundi en dur). */
    public function resolveWeekStart(Company $company, string $anyDate): Carbon
    {
        $d = Carbon::parse($anyDate)->startOfDay();
        $ws = WeekStartResolver::resolve($company); // 0=dim … 6=sam
        $offset = (($d->dayOfWeek - $ws) + 7) % 7;   // Carbon dayOfWeek : 0=dim … 6=sam

        return $d->copy()->subDays($offset);
    }

    /** Prévisualisation (lecture seule) : bilan détaillé + candidats pour les besoins. */
    public function preview(int $companyId, int $weekTemplateId, string $targetDate, ?array $visibleIds = null): array
    {
        [$company, $wt, $weekStart, $lines] = $this->context($companyId, $weekTemplateId, $targetDate);

        $busy = [];
        $nominative = [];
        $needs = [];
        $prospects = [];
        $summary = ['planned_count' => 0, 'applicable' => 0, 'to_confirm' => 0, 'blocked' => 0, 'unfilled' => 0];

        foreach ($lines as $ln) {
            $date = $weekStart->copy()->addDays($ln->day_index)->toDateString();
            $tpl = $this->templateOf($companyId, $ln->schedule_template_id);
            if (! $tpl) {
                $base = ['line_id' => $ln->id, 'day_index' => $ln->day_index, 'date' => $date, 'status' => 'blocked', 'reason' => self::BLOCK_INVALID_TEMPLATE];
                $ln->kind === 'needs' ? $needs[] = $base + ['kind' => 'needs', 'headcount' => (int) $ln->headcount, 'candidates' => []] : $nominative[] = $base + ['kind' => 'nominative'];
                $summary['blocked'] += $ln->kind === 'needs' ? (int) $ln->headcount : 1;

                continue;
            }
            [$s, $e] = $this->hours($tpl, $date);

            if ($ln->kind === 'nominative') {
                $c = $this->classify($companyId, (int) $ln->employee_id, $date, $s, $e, $tpl, $visibleIds, $busy);
                $emp = $ln->employee_id ? Employee::withoutGlobalScopes()->where('company_id', $companyId)->find($ln->employee_id) : null;
                $nominative[] = [
                    'line_id' => $ln->id, 'kind' => 'nominative', 'day_index' => $ln->day_index, 'date' => $date,
                    'employee_id' => $ln->employee_id, 'employee_name' => $emp?->full_name, 'template_name' => $tpl->name,
                    'status' => $c['status'], 'reason' => $c['reason'], 'warning' => $c['warning'],
                ];
                if ($c['status'] === 'applicable') {
                    $summary['applicable']++;
                    $this->markBusy($busy, (int) $ln->employee_id, $s, $e);
                    $prospects[] = $this->prospect($companyId, (int) $ln->employee_id, $date, $s, $e, $tpl);
                } else {
                    $summary['blocked']++;
                }
            } else {
                $cands = $this->candidates($companyId, $date, $s, $e, $tpl, $ln, $visibleIds, $busy);
                $needs[] = [
                    'line_id' => $ln->id, 'kind' => 'needs', 'day_index' => $ln->day_index, 'date' => $date,
                    'headcount' => (int) $ln->headcount, 'template_name' => $tpl->name,
                    'coverage_category_id' => $tpl->coverage_category_id, 'candidates' => $cands,
                ];
                $summary['to_confirm'] += (int) $ln->headcount;
                $summary['unfilled'] += (int) $ln->headcount;
            }
        }

        $overstaffing = app(OverstaffingEvaluator::class)->evaluateMany($companyId, $prospects);
        $summary['planned_count'] = $summary['applicable'] + $summary['to_confirm'];

        return [
            'week_template_id' => $wt->id, 'name' => $wt->name, 'strategy' => $wt->strategy,
            'week_start' => $weekStart->toDateString(), 'week_start_day' => WeekStartResolver::resolve($company),
            'nominative' => $nominative, 'needs' => $needs, 'summary' => $summary,
            'overstaffing' => $overstaffing, 'mode' => app(OverstaffingPolicy::class)->mode($companyId),
        ];
    }

    /**
     * Commit atomique. $assignments = [['line_id'=>int,'employee_id'=>int], ...] pour les lignes « besoins »
     * confirmées par le manager (plusieurs par ligne, dans la limite du headcount). Nominatif = auto.
     */
    public function commit(int $companyId, int $weekTemplateId, string $targetDate, array $assignments, ?array $visibleIds, ?string $ack, ?string $reason, ?int $actorId, bool $skipOverstaffing = false, bool $materialize = true, bool $detailed = false): array
    {
        [$company, $wt, $weekStart, $lines] = $this->context($companyId, $weekTemplateId, $targetDate);

        return DB::transaction(function () use ($companyId, $wt, $weekStart, $lines, $assignments, $visibleIds, $ack, $reason, $actorId, $weekTemplateId, $targetDate, $skipOverstaffing, $materialize, $detailed) {
            $detNom = [];
            $detNeeds = [];
            // Map assignments par ligne (ordre préservé, limité au headcount).
            $byLine = [];
            foreach ($assignments as $a) {
                $lid = (int) ($a['line_id'] ?? 0);
                $eid = (int) ($a['employee_id'] ?? 0);
                if ($lid && $eid) {
                    $byLine[$lid][] = $eid;
                }
            }

            $busy = [];
            $toCreate = [];   // [employeeId, date, start, end, template]
            $prospects = [];
            $blocked = [];
            $unfilled = 0;

            foreach ($lines as $ln) {
                $date = $weekStart->copy()->addDays($ln->day_index)->toDateString();
                $tpl = $this->templateOf($companyId, $ln->schedule_template_id);
                if (! $tpl) {
                    $blocked[] = ['line_id' => $ln->id, 'date' => $date, 'reason' => self::BLOCK_INVALID_TEMPLATE];
                    $unfilled += $ln->kind === 'needs' ? (int) $ln->headcount : 1;
                    if ($detailed) {
                        $base = ['line_id' => $ln->id, 'day_index' => $ln->day_index, 'date' => $date, 'status' => 'blocked', 'reason' => self::BLOCK_INVALID_TEMPLATE, 'warning' => null];
                        $ln->kind === 'needs' ? $detNeeds[] = $base + ['kind' => 'needs', 'headcount' => (int) $ln->headcount, 'candidates' => []] : $detNom[] = $base + ['kind' => 'nominative', 'employee_id' => $ln->employee_id];
                    }

                    continue;
                }
                [$s, $e] = $this->hours($tpl, $date);

                if ($detailed && $ln->kind === 'needs') {
                    $detNeeds[] = [
                        'line_id' => $ln->id, 'kind' => 'needs', 'day_index' => $ln->day_index, 'date' => $date,
                        'headcount' => (int) $ln->headcount, 'template_name' => $tpl->name,
                        'coverage_category_id' => $tpl->coverage_category_id,
                        'candidates' => $this->candidates($companyId, $date, $s, $e, $tpl, $ln, $visibleIds, $busy),
                    ];
                }

                $targets = $ln->kind === 'nominative'
                    ? [(int) $ln->employee_id]
                    : array_slice($byLine[$ln->id] ?? [], 0, (int) $ln->headcount);

                $placed = 0;
                foreach ($targets as $empId) {
                    $c = $this->classify($companyId, $empId, $date, $s, $e, $tpl, $visibleIds, $busy);
                    if ($detailed && $ln->kind === 'nominative') {
                        $detNom[] = [
                            'line_id' => $ln->id, 'kind' => 'nominative', 'day_index' => $ln->day_index, 'date' => $date,
                            'employee_id' => $empId, 'template_name' => $tpl->name,
                            'status' => $c['status'], 'reason' => $c['reason'], 'warning' => $c['warning'],
                        ];
                    }
                    if ($c['status'] !== 'applicable') {
                        $blocked[] = ['line_id' => $ln->id, 'date' => $date, 'employee_id' => $empId, 'reason' => $c['reason']];

                        continue;
                    }
                    $this->markBusy($busy, $empId, $s, $e);
                    $toCreate[] = [$empId, $date, $s, $e, $tpl];
                    $prospects[] = $this->prospect($companyId, $empId, $date, $s, $e, $tpl);
                    $placed++;
                }
                if ($ln->kind === 'needs') {
                    $unfilled += max(0, (int) $ln->headcount - $placed);
                }
            }

            if (count($toCreate) > self::MAX_CELLS) {
                throw new \DomainException('Apply limit exceeded ('.count($toCreate).' > '.self::MAX_CELLS.').');
            }

            // SUREFFECTIF CUMULATIF (revalidé au commit) — confirmation serveur selon politique.
            // $skipOverstaffing : délégué à l'appelant (M4 évalue le sureffectif CUMULÉ sur toutes les semaines).
            if (! $skipOverstaffing) {
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
            }

            // Création TOUT-OU-RIEN (toute exception annule tout le lot). Matérialise activités+pauses (M1)
            // sauf en dry-run de prévisualisation ($materialize=false) où les activités ne changent aucun contrôle.
            $created = [];
            foreach ($toCreate as [$empId, $date, $s, $e, $tpl]) {
                $created[] = app(CreateShiftUseCase::class)->execute(
                    companyId: $companyId, employeeId: $empId, date: $date,
                    startAt: $s->toDateTimeString(), endAt: $e->toDateTimeString(), timezone: 'Europe/Paris',
                    scheduleTemplateId: $tpl->id, workLocationId: $tpl->work_location_id,
                    skipOverstaffing: true, materializeComposition: $materialize,
                )->id;
            }

            // L'opération réversible UNIQUE (apply_week_template) est enregistrée par le CONTRÔLEUR après
            // commit (comme bulk_template) — pas ici, afin que le REDO (qui rappelle commit) ne double pas l'op.
            return [
                'created' => $created, 'created_count' => count($created),
                'unfilled' => $unfilled, 'blocked' => $blocked, 'prospects' => $prospects,
                'nominative' => $detNom, 'needs' => $detNeeds,
            ];
        });
    }

    // ── internals ──

    /** @return array{0:Company,1:WeekTemplate,2:Carbon,3:\Illuminate\Support\Collection} */
    private function context(int $companyId, int $weekTemplateId, string $targetDate): array
    {
        $company = Company::findOrFail($companyId);
        $wt = WeekTemplate::withoutGlobalScopes()->where('id', $weekTemplateId)->where('company_id', $companyId)->first();
        if (! $wt) {
            throw new \DomainException('Week template not found or does not belong to this company.');
        }
        $weekStart = $this->resolveWeekStart($company, $targetDate);
        $lines = $wt->lines()->get();

        return [$company, $wt, $weekStart, $lines];
    }

    private function templateOf(int $companyId, ?int $id): ?ScheduleTemplate
    {
        if (! $id) {
            return null;
        }

        return ScheduleTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $id)->where('enabled', true)->first();
    }

    private function hours(ScheduleTemplate $t, string $date): array
    {
        $start = Carbon::parse($date.' '.substr($t->start_time, 0, 5));
        $end = Carbon::parse($date.' '.substr($t->end_time, 0, 5));
        if ($end->lte($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /** @return array{status:string, reason:?string, warning:?string} */
    private function classify(int $companyId, int $employeeId, string $date, Carbon $s, Carbon $e, ScheduleTemplate $tpl, ?array $visibleIds, array $busy): array
    {
        if (! $employeeId) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_INVALID_EMPLOYEE, 'warning' => null];
        }
        if ($visibleIds !== null && ! in_array($employeeId, $visibleIds, true)) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_OUT_OF_SCOPE, 'warning' => null];
        }
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $employeeId)->first();
        if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_INACTIVE, 'warning' => null];
        }
        $contract = $employee->currentContract;
        if (! $contract || $contract->status !== EmploymentContract::STATUS_ACTIVE) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_NO_CONTRACT, 'warning' => null];
        }
        // M4 (ADR-727) — validité du contrat À LA DATE du shift (contrat expirant entre deux semaines).
        $d = Carbon::parse($date);
        if (($contract->start_date && Carbon::parse($contract->start_date)->gt($d))
            || ($contract->end_date && Carbon::parse($contract->end_date)->lt($d))) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_CONTRACT_EXPIRED, 'warning' => null];
        }
        $absent = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
            ->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)->exists();
        if ($absent) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_ABSENT, 'warning' => null];
        }
        // Chevauchement : existant (DB, non annulé) OU déjà placé dans CE lot (busy).
        if ($this->busyOverlap($busy, $employeeId, $s, $e) || Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)->notCancelled()
            ->overlapping($s, $e)->exists()) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_OVERLAP, 'warning' => null];
        }
        try {
            app(ShiftRuleValidator::class)->validate(
                companyId: $companyId, employeeId: $employeeId, contract: $contract,
                startAt: $s, endAt: $e, date: $date, breakMinutes: (int) ($tpl->toSnapshot()['break_duration_minutes'] ?? 0),
            );
        } catch (\DomainException $ex) {
            return ['status' => 'blocked', 'reason' => self::BLOCK_REGULATORY, 'warning' => null];
        }
        // Demande en attente (risque) — distincte d'une absence confirmée ; ne bloque pas.
        $pending = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)->exists();

        return ['status' => 'applicable', 'reason' => null, 'warning' => $pending ? 'pending_leave' : null];
    }

    /** Candidats éligibles pour une ligne « besoins » (critères réellement disponibles ; jamais inventés). */
    private function candidates(int $companyId, string $date, Carbon $s, Carbon $e, ScheduleTemplate $tpl, $ln, ?array $visibleIds, array $busy): array
    {
        $q = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('status', Employee::STATUS_ACTIVE);
        if ($ln->department_id) {
            $q->where('department_id', $ln->department_id);
        }
        $out = [];
        foreach ($q->orderBy('first_name')->limit(60)->get() as $emp) {
            if (count($out) >= 15) {
                break;
            }
            $c = $this->classify($companyId, $emp->id, $date, $s, $e, $tpl, $visibleIds, $busy);
            if ($c['status'] === 'applicable') {
                $out[] = ['employee_id' => $emp->id, 'name' => $emp->full_name, 'eligible' => true,
                    'reason' => $c['warning'] === 'pending_leave' ? 'pending_leave' : 'available'];
            }
        }

        return $out;
    }

    private function prospect(int $companyId, int $employeeId, string $date, Carbon $s, Carbon $e, ScheduleTemplate $tpl): array
    {
        return [
            'employee_id' => $employeeId, 'date' => $date,
            'start_at' => $s->toDateTimeString(), 'end_at' => $e->toDateTimeString(),
            'work_location_id' => $tpl->work_location_id, 'template_snapshot' => $tpl->toSnapshot(), 'ignore_shift_id' => null,
        ];
    }

    private function markBusy(array &$busy, int $employeeId, Carbon $s, Carbon $e): void
    {
        $busy[$employeeId][] = [$s->getTimestamp(), $e->getTimestamp()];
    }

    private function busyOverlap(array $busy, int $employeeId, Carbon $s, Carbon $e): bool
    {
        foreach ($busy[$employeeId] ?? [] as [$bs, $be]) {
            if ($s->getTimestamp() < $be && $bs < $e->getTimestamp()) {
                return true;
            }
        }

        return false;
    }
}
