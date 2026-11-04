<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\User;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Carbon;

/**
 * E3-B / B3 (ADR-699) — PROJECTION métier, lecture seule, strictement scopée, de l'historique d'une
 * feuille de temps. NE crée PAS de 2ᵉ moteur d'audit : lit les événements DÉJÀ écrits dans
 * `company_audit_logs` et les présente de façon assainie (pas de dump technique brut).
 *
 * Deux sources :
 *  1. Événements de workflow de la période — `target_type = 'timesheet_period'`, `target_id = {id}`
 *     (generated/submitted/approved/rejected/reopened/locked), avec note/motif éventuel.
 *  2. Corrections de pointage tracées — `action = 'time_entry.updated'` du salarié de la période,
 *     bornées à l'intervalle de la période (metadata.employee_id + date old/new dans [start, end]),
 *     avec QUI, QUAND, QUELLE donnée a changé et POURQUOI (motif obligatoire).
 *
 * Le périmètre (company, période, salarié) est imposé par l'appelant (contrôleur scopé). Rien n'est
 * exposé hors de cette période/salarié.
 */
class TimesheetHistoryReadModel
{
    private const WORKFLOW_ACTIONS = [
        'timesheet.generated', 'timesheet.submitted', 'timesheet.approved',
        'timesheet.rejected', 'timesheet.reopened', 'timesheet.locked',
    ];

    public static function forPeriod(int $companyId, TimesheetPeriod $period): array
    {
        $workflow = CompanyAuditLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('target_type', 'timesheet_period')
            ->where('target_id', (string) $period->id)
            ->whereIn('action', self::WORKFLOW_ACTIONS)
            ->orderBy('created_at')
            ->get();

        $corrections = CompanyAuditLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('action', 'time_entry.updated')
            ->where('metadata->employee_id', $period->employee_id)
            ->orderBy('created_at')
            ->get()
            ->filter(fn ($log) => self::correctionInPeriod($log, $period));

        $all = $workflow->concat($corrections);

        $actorNames = self::resolveActors($all->pluck('actor_id')->filter()->unique()->all());

        // Tri chronologique robuste : created_at puis id (ULID monotone) pour départager une même seconde.
        return $all
            ->sortBy(fn (CompanyAuditLog $log) => [optional($log->created_at)->getTimestamp() ?? 0, (string) $log->id])
            ->values()
            ->map(function (CompanyAuditLog $log) use ($actorNames) {
                $meta = $log->metadata ?? [];
                $isCorrection = $log->action === 'time_entry.updated';

                return [
                    'at' => optional($log->created_at)->toIso8601String(),
                    'actor' => $actorNames[$log->actor_id] ?? null,
                    'action' => $log->action,
                    'kind' => $isCorrection ? 'correction' : 'workflow',
                    // Motif : note d'approbation / motif de refus (workflow) ou motif de correction.
                    'reason' => $isCorrection
                        ? ($meta['reason'] ?? null)
                        : ($meta['approval_note'] ?? $meta['rejection_reason'] ?? null),
                    // Correction : date concernée + ancien/nouveau (assaini, pas de dump brut).
                    'entry_date' => $isCorrection ? (self::correctionDate($log) ?? null) : null,
                    'change' => $isCorrection ? [
                        'old' => [
                            'clock_in' => $meta['old_data']['clock_in'] ?? null,
                            'clock_out' => $meta['old_data']['clock_out'] ?? null,
                        ],
                        'new' => [
                            'clock_in' => $meta['new_data']['clock_in'] ?? null,
                            'clock_out' => $meta['new_data']['clock_out'] ?? null,
                        ],
                    ] : null,
                ];
            })
            ->all();
    }

    private static function correctionDate(CompanyAuditLog $log): ?string
    {
        $meta = $log->metadata ?? [];
        $raw = $meta['new_data']['date'] ?? $meta['old_data']['date'] ?? null;

        return $raw ? Carbon::parse($raw)->toDateString() : null;
    }

    private static function correctionInPeriod(CompanyAuditLog $log, TimesheetPeriod $period): bool
    {
        $date = self::correctionDate($log);
        if (! $date) {
            return false;
        }

        return $date >= $period->period_start->toDateString()
            && $date <= $period->period_end->toDateString();
    }

    /** @param  array<int>  $ids */
    private static function resolveActors(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return User::whereIn('id', $ids)->get(['id', 'name', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(function (User $u) {
                $name = $u->name ?: trim(($u->first_name ?? '').' '.($u->last_name ?? ''));

                return [$u->id => ($name ?: $u->email)];
            })
            ->all();
    }
}
