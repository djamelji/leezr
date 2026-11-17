<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\User;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload;
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;

/**
 * ADR-819 (Company 2.0) — ONE hiring file, read as a whole : progression per item, history, participants,
 * reminders, and a REVISION token. Several authorised managers can work on the same file and the
 * candidate can complete it in parallel : a manager's write carrying a stale revision is refused (409)
 * instead of silently overwriting what someone else changed meanwhile. No new table, no second engine :
 * it reads the onboarding file (ADR-796), its staged values, deposits and the company audit trail.
 */
final class HiringFileReadModel
{
    /** Progress states of one item, as shown to the company. */
    public const STATES = ['to_fill', 'in_progress', 'provided', 'to_verify', 'refused', 'validated', 'not_applicable'];

    public static function revision(Inv $inv): string
    {
        $values = OnboardingValue::where('onboarding_invitation_id', $inv->id)->max('updated_at');
        $uploads = OnboardingUpload::where('onboarding_invitation_id', $inv->id)->selectRaw('max(id) as i, max(updated_at) as u')->first();

        return substr(sha1(implode('|', [$inv->updated_at?->format('U.u'), $inv->status, $inv->snapshot_version, $values, $uploads?->i, $uploads?->u])), 0, 16);
    }

    /** Bucket of the hiring space (who has to act next). */
    public static function bucket(array $row): string
    {
        return match (true) {
            (bool) ($row['finalized'] ?? false) => 'done',
            in_array($row['status'], [Inv::REVOKED, Inv::EXPIRED], true) => 'abandoned',
            $row['status'] === Inv::VALIDATED => 'to_finalize',
            $row['status'] === Inv::SUBMITTED || ($row['documents_blocked'] ?? 0) > 0 => 'to_verify',
            in_array($row['status'], [Inv::SENT, Inv::IN_PROGRESS, Inv::CHANGES_REQUESTED], true) => 'waiting_candidate',
            default => 'waiting_company', // brouillon : la société n'a pas encore envoyé ni ouvert le dossier
        };
    }

    /** Adds a progress state to each field / document of a file detail (ADR-796 detail payload). */
    public static function withStates(array $detail): array
    {
        $submitted = in_array($detail['status'], [Inv::SUBMITTED, Inv::VALIDATED], true);
        $detail['fields'] = array_map(function ($f) use ($submitted) {
            $f['state'] = match (true) {
                $f['decision'] === OnboardingValue::NOT_REQUESTED => 'not_applicable',
                in_array($f['decision'], [OnboardingValue::ACCEPTED, OnboardingValue::KEPT_EXISTING], true) => 'validated',
                ! ($f['filled'] ?? false) => ($f['current'] ?? null) !== null && ($f['current'] ?? '') !== '' ? 'provided' : 'to_fill',
                ($f['conflict'] ?? false) || $submitted => 'to_verify',
                default => 'provided',
            };

            return $f;
        }, $detail['fields'] ?? []);
        $detail['documents'] = array_map(function ($d) {
            $scan = $d['scan']['status'] ?? null;
            $d['state'] = match (true) {
                in_array($scan, [OnboardingUpload::INFECTED, OnboardingUpload::SCAN_FAILED], true) => 'refused',
                in_array($d['dms_status'] ?? null, ['rejected', 'to_replace'], true) => 'refused',
                ($d['dms_status'] ?? null) === 'approved' => 'validated',
                $scan === OnboardingUpload::QUARANTINED => 'in_progress',
                in_array($d['dms_status'] ?? null, ['submitted', 'in_review'], true) || $scan === OnboardingUpload::INGESTED => 'to_verify',
                default => 'to_fill',
            };

            return $d;
        }, $detail['documents'] ?? []);
        $all = array_merge($detail['fields'], $detail['documents']);
        $detail['progress'] = [
            'total' => count($all),
            'done' => count(array_filter($all, fn ($i) => in_array($i['state'], ['provided', 'to_verify', 'validated', 'not_applicable'], true))),
            'validated' => count(array_filter($all, fn ($i) => in_array($i['state'], ['validated', 'not_applicable'], true))),
            'refused' => count(array_filter($all, fn ($i) => $i['state'] === 'refused')),
        ];

        return $detail;
    }

    /** @return array{history: list<array>, participants: list<array>, reminders: int} */
    public static function trail(Inv $inv): array
    {
        $logs = CompanyAuditLog::where('company_id', $inv->company_id)->where('target_type', 'onboarding_invitation')->where('target_id', (string) $inv->id)
            ->orderByDesc('id')->limit(100)->get(['id', 'action', 'actor_id', 'actor_type', 'created_at']);
        $users = User::whereIn('id', $logs->pluck('actor_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $name = fn ($id) => isset($users[$id]) ? trim($users[$id]->first_name.' '.$users[$id]->last_name) : null;

        $participants = $logs->where('actor_type', 'user')->pluck('actor_id')->filter()->unique()
            ->map(fn ($id) => ['kind' => 'user', 'id' => (int) $id, 'name' => $name($id), 'actions' => $logs->where('actor_id', $id)->count()])->values()->all();
        if ($logs->contains('actor_type', 'candidate')) {
            $participants[] = ['kind' => 'candidate', 'id' => null, 'name' => null, 'actions' => $logs->where('actor_type', 'candidate')->count()];
        }

        return [
            'history' => $logs->map(fn ($l) => ['action' => $l->action, 'actor_type' => $l->actor_type, 'actor' => $l->actor_id ? $name($l->actor_id) : null,
                'at' => $l->created_at?->toIso8601String()])->values()->all(),
            'participants' => $participants,
            'reminders' => $logs->whereIn('action', ['onboarding.invitation_mailed', 'onboarding.link_issued'])->count(),
        ];
    }
}
