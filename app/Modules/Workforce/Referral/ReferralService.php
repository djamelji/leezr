<?php

namespace App\Modules\Workforce\Referral;

use App\Core\Audit\AuditLogger;
use App\Core\Email\TransactionalMailChannel;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\MailLocale;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation;
use App\Modules\Workforce\Onboarding\OnboardingInvitationService;
use App\Modules\Workforce\Onboarding\OnboardingPlanner;
use App\Modules\Workforce\Referral\Models\Referral;
use App\Modules\Workforce\Referral\Models\ReferralSettings;
use App\Notifications\Referral\ReferralInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * ADR-819 (Company 2.0) — Workforce Parrainage, company side.
 *
 * Confidentiality by construction :
 *  - HR only sees CONSENTED applications (before consent : a count, nothing else) ;
 *  - the referrer only sees a coarse status of his own referrals (never the HR decision nor its reason,
 *    never the candidate's application, never the hiring file) ;
 *  - links are random, hashed, expiring, single-use ; per-referrer daily limits stop bulk sending ;
 *  - retention : a closed referral is anonymised after the company's retention period.
 * Conversion reuses the hiring engine (ADR-796/818) : one preparatory record + one hiring file, prefilled,
 * no account created.
 */
final class ReferralService
{
    public const CONSENT_VERSION = '2026-12';

    public const DAILY_LIMIT = 10;

    public function __construct(private Company $company, private ?User $actor = null) {}

    // ── Settings & opportunities ─────────────────────────────────────────

    public function settings(): ReferralSettings
    {
        return ReferralSettings::for($this->company->id);
    }

    public function saveSettings(array $data): ReferralSettings
    {
        $ids = array_values(array_unique(array_map('intval', $data['job_role_ids'] ?? [])));
        $valid = JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->whereIn('id', $ids)->pluck('id')->all();
        $s = $this->settings();
        $s->fill([
            'mode' => $data['mode'], 'job_role_ids' => array_values(array_intersect($ids, $valid)), 'notify_reviewers' => (bool) ($data['notify_reviewers'] ?? true),
            'reward_note' => $data['reward_note'] ?? null, 'retention_days' => (int) ($data['retention_days'] ?? 180), 'link_days' => (int) ($data['link_days'] ?? 30),
            'updated_by' => $this->actor?->id,
        ])->save();
        $this->audit('referral.settings_updated', null, ['mode' => $s->mode, 'positions' => count($s->job_role_ids)]);

        return $s;
    }

    /** Positions open to referral (only those the company chose). */
    public function opportunities(): array
    {
        $s = $this->settings();

        return JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->whereIn('id', $s->job_role_ids ?? [])
            ->with('department:id,name')->orderBy('title')->get()
            ->map(fn (JobRole $j) => ['id' => $j->id, 'title' => $j->title, 'description' => $j->description, 'department' => $j->department?->name])->values()->all();
    }

    // ── Referrer (employee) ──────────────────────────────────────────────

    public function recommend(array $data): array
    {
        $this->assertCanRefer(Referral::CHANNEL_RECOMMENDATION, (int) $data['job_role_id']);
        $raw = bin2hex(random_bytes(32));
        $ref = Referral::create([
            'company_id' => $this->company->id, 'referrer_user_id' => $this->actor->id, 'referrer_employee_id' => $this->referrerEmployee()?->id,
            'job_role_id' => (int) $data['job_role_id'], 'channel' => Referral::CHANNEL_RECOMMENDATION, 'status' => Referral::AWAITING_CONSENT,
            'first_name' => $data['first_name'], 'last_name' => $data['last_name'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'referred_name' => trim($data['first_name'].' '.($data['last_name'] ?? '')), // ce que le PARRAIN a saisi, figé
            'referrer_note' => $data['note'] ?? null, 'token_hash' => hash('sha256', $raw), 'link_expires_at' => now()->addDays($this->settings()->link_days),
        ]);
        $url = $this->url($raw);
        $mailed = false;
        if ($ref->email && TransactionalMailChannel::available()) {
            try {
                $locale = MailLocale::forCompany($this->company);
                Notification::route('mail', $ref->email)->notifyNow((new ReferralInvitationNotification(
                    $this->company->name, trim(($this->actor->first_name ?? '').' '.($this->actor->last_name ?? '')), $ref->jobRole?->title ?? '', $url,
                    $ref->link_expires_at->locale($locale)->isoFormat('LL')))->locale($locale));
                $mailed = true;
            } catch (\Throwable) {
                $mailed = false; // le parrain peut encore transmettre le lien lui-même
            }
        }
        $this->audit('referral.recommended', $ref, ['mailed' => $mailed]);

        return ['referral' => $this->forReferrer($ref), 'url' => $url, 'mailed' => $mailed];
    }

    public function shareLink(int $jobRoleId): array
    {
        $this->assertCanRefer(Referral::CHANNEL_LINK, $jobRoleId);
        $raw = bin2hex(random_bytes(32));
        $ref = Referral::create([
            'company_id' => $this->company->id, 'referrer_user_id' => $this->actor->id, 'referrer_employee_id' => $this->referrerEmployee()?->id,
            'job_role_id' => $jobRoleId, 'channel' => Referral::CHANNEL_LINK, 'status' => Referral::LINK_SHARED,
            'token_hash' => hash('sha256', $raw), 'link_expires_at' => now()->addDays($this->settings()->link_days),
        ]);
        $this->audit('referral.link_shared', $ref);

        return ['referral' => $this->forReferrer($ref), 'url' => $this->url($raw)];
    }

    /** The referrer's own referrals — coarse status only (confidentiality of the HR process). */
    public function mine(): array
    {
        return Referral::where('company_id', $this->company->id)->where('referrer_user_id', $this->actor->id)->with('jobRole:id,title')
            ->latest('id')->limit(100)->get()->map(fn (Referral $r) => $this->forReferrer($r))->values()->all();
    }

    public function withdraw(Referral $ref): void
    {
        abort_unless((int) $ref->referrer_user_id === (int) $this->actor->id, 404);
        if (! in_array($ref->status, [Referral::LINK_SHARED, Referral::AWAITING_CONSENT], true)) {
            throw ValidationException::withMessages(['status' => __('referral.not_withdrawable')]);
        }
        $ref->forceFill(['status' => Referral::WITHDRAWN, 'token_hash' => null, 'purge_after' => now()])->save();
        $this->audit('referral.withdrawn', $ref);
    }

    private function forReferrer(Referral $r): array
    {
        $public = match (true) {
            $r->status === Referral::LINK_SHARED && $r->link_expires_at?->isPast() => 'expired',
            $r->status === Referral::AWAITING_CONSENT && $r->link_expires_at?->isPast() => 'expired',
            $r->status === Referral::LINK_SHARED => 'link_shared',
            $r->status === Referral::AWAITING_CONSENT => 'awaiting_candidate',
            in_array($r->status, Referral::REVIEWABLE, true) => 'received',
            $r->status === Referral::WITHDRAWN => 'withdrawn',
            $r->status === Referral::EXPIRED => 'expired',
            default => 'closed', // décision RH (retenue, déclinée, embauche) : jamais détaillée au parrain
        };

        return ['id' => $r->id, 'channel' => $r->channel, 'position' => $r->jobRole?->title, 'status' => $public,
            // Le parrain voit le prénom qu'il a lui-même saisi ; jamais ce que le candidat a transmis.
            'name' => $r->channel === Referral::CHANNEL_RECOMMENDATION && ! $r->anonymized_at ? $r->referred_name : null,
            'created_at' => $r->created_at?->toIso8601String(), 'link_expires_at' => $r->link_expires_at?->toIso8601String()];
    }

    // ── HR review ────────────────────────────────────────────────────────

    public function queue(): array
    {
        $rows = Referral::where('company_id', $this->company->id)->whereIn('status', array_merge(Referral::REVIEWABLE, [Referral::DECLINED, Referral::CONVERTED]))
            ->whereNull('anonymized_at')->with('jobRole:id,title')->latest('id')->limit(300)->get();
        $referrers = User::whereIn('id', $rows->pluck('referrer_user_id')->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return [
            'items' => $rows->map(fn (Referral $r) => $this->forHr($r, $referrers[$r->referrer_user_id] ?? null))->values()->all(),
            // Avant consentement, rien n'est montré : seulement un nombre.
            'awaiting_consent' => Referral::where('company_id', $this->company->id)->whereIn('status', [Referral::AWAITING_CONSENT, Referral::LINK_SHARED])
                ->where('link_expires_at', '>', now())->count(),
        ];
    }

    public function detail(Referral $ref): array
    {
        $this->assertReviewable($ref);
        if ($ref->status === Referral::SUBMITTED) {
            $ref->forceFill(['status' => Referral::IN_REVIEW])->save(); // ouverture par les RH : « en cours d'examen »
            $this->audit('referral.opened', $ref);
        }
        $referrer = User::find($ref->referrer_user_id, ['id', 'first_name', 'last_name']);

        return $this->forHr($ref, $referrer) + ['candidate_message' => $ref->candidate_message, 'referrer_note' => $ref->referrer_note,
            'review_note' => $ref->review_note, 'info_request' => $ref->info_request, 'consent_at' => $ref->consent_at?->toIso8601String(),
            'duplicates' => $this->duplicates($ref)];
    }

    /** Possible duplicates : an employee, a hiring file or another referral with the same e-mail / phone / name. */
    public function duplicates(Referral $ref): array
    {
        $out = [];
        $emp = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where(fn ($q) => $q->when($ref->email, fn ($w) => $w->orWhere('email', $ref->email))
                ->orWhere(fn ($w) => $w->where('first_name', $ref->first_name)->where('last_name', $ref->last_name)))
            ->limit(5)->get(['id', 'first_name', 'last_name', 'status', 'preparatory_at']);
        foreach ($emp as $e) {
            $out[] = ['kind' => $e->isPreparatory() ? 'hiring' : 'employee', 'id' => $e->id, 'label' => trim($e->first_name.' '.$e->last_name), 'person_ref' => 'employee-'.$e->id];
        }
        if ($ref->email) {
            Referral::where('company_id', $this->company->id)->where('id', '!=', $ref->id)->where('email', $ref->email)->whereNull('anonymized_at')
                ->whereIn('status', array_merge(Referral::REVIEWABLE, [Referral::CONVERTED, Referral::DECLINED]))->limit(5)->get()
                ->each(function (Referral $o) use (&$out) {
                    $out[] = ['kind' => 'referral', 'id' => $o->id, 'label' => trim($o->first_name.' '.$o->last_name), 'status' => $o->status];
                });
        }

        return $out;
    }

    public function decide(Referral $ref, string $decision, ?string $note): array
    {
        $this->assertReviewable($ref);
        if (in_array($ref->status, [Referral::CONVERTED, Referral::DECLINED], true)) {
            throw ValidationException::withMessages(['status' => __('referral.already_decided')]);
        }
        $retention = $this->settings()->retention_days;
        $fill = ['reviewed_by' => $this->actor->id, 'reviewed_at' => now()];
        if ($decision === 'request_info') {
            if (! $note) {
                throw ValidationException::withMessages(['note' => __('referral.info_message_required')]);
            }
            $raw = bin2hex(random_bytes(32));
            $fill += ['status' => Referral::INFO_REQUESTED, 'info_request' => $note, 'token_hash' => hash('sha256', $raw), 'link_expires_at' => now()->addDays($this->settings()->link_days)];
            $ref->forceFill($fill)->save();
            $mailed = $this->mailInfoRequest($ref, $this->url($raw));
            $this->audit('referral.info_requested', $ref, ['mailed' => $mailed]);

            return ['referral' => $this->detail($ref->refresh()), 'url' => $this->url($raw), 'mailed' => $mailed];
        }
        $fill += match ($decision) {
            'accept' => ['status' => Referral::ACCEPTED, 'review_note' => $note],
            'decline' => ['status' => Referral::DECLINED, 'review_note' => $note, 'token_hash' => null, 'purge_after' => now()->addDays($retention)],
        };
        $ref->forceFill($fill)->save();
        $this->audit('referral.'.($decision === 'accept' ? 'accepted' : 'declined'), $ref);

        return ['referral' => $this->detail($ref->refresh())];
    }

    /** Converts a retained application into ONE hiring file (existing engine), prefilled, no account. */
    public function convert(Referral $ref, string $hireDate): array
    {
        $this->assertReviewable($ref);
        if ($ref->status !== Referral::ACCEPTED) {
            throw ValidationException::withMessages(['status' => __('referral.convert_requires_acceptance')]);
        }
        $job = $ref->job_role_id ? JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->find($ref->job_role_id) : null;
        // Les éléments EN VIGUEUR pour ce poste (pré-sélection de l'assistant « Embaucher »), jamais tout le catalogue.
        $items = array_values(array_map(fn ($i) => ['kind' => $i['kind'], 'code' => $i['code'], 'required' => (bool) $i['suggested_required']],
            array_filter((new OnboardingPlanner($this->company))->propose($job), fn ($i) => $i['suggested'])));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('referral.nothing_to_collect')]);
        }
        $inv = DB::transaction(function () use ($ref, $job, $items, $hireDate) {
            $locked = Referral::whereKey($ref->id)->lockForUpdate()->first();
            if ($locked->status !== Referral::ACCEPTED) {
                throw ValidationException::withMessages(['status' => __('referral.already_decided')]);
            }
            $inv = (new OnboardingInvitationService($this->company, $this->actor))->create([
                'first_name' => $ref->first_name, 'last_name' => $ref->last_name ?: '—', 'email' => $ref->email, 'candidate_email' => $ref->email,
                'hire_date' => $hireDate, 'job_role_id' => $job?->id, 'items' => $items, 'purpose' => 'hiring_onboarding',
                'legal_basis' => 'contract_preparation', 'notice_text' => __('referral.notice_text', ['company' => $this->company->name]),
            ]);
            $locked->forceFill(['status' => Referral::CONVERTED, 'onboarding_invitation_id' => $inv->id, 'purge_after' => now()->addDays($this->settings()->retention_days)])->save();

            return $inv;
        });
        $this->audit('referral.converted', $ref, ['onboarding_invitation_id' => $inv->id]);

        return ['hiring_file_id' => $inv->id];
    }

    private function forHr(Referral $r, ?User $referrer): array
    {
        return ['id' => $r->id, 'channel' => $r->channel, 'status' => $r->status, 'position' => $r->jobRole?->title, 'job_role_id' => $r->job_role_id,
            'first_name' => $r->first_name, 'last_name' => $r->last_name, 'email' => $r->email, 'phone' => $r->phone,
            'referrer' => $referrer ? trim($referrer->first_name.' '.$referrer->last_name) : null,
            'submitted_at' => $r->consent_at?->toIso8601String(), 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'hiring_file_id' => $r->onboarding_invitation_id];
    }

    // ── Guards & helpers ─────────────────────────────────────────────────

    private function assertCanRefer(string $channel, int $jobRoleId): void
    {
        if ($this->referrerEmployee() === null) {
            throw ValidationException::withMessages(['referrer' => __('referral.employee_required')]); // un SALARIÉ recommande
        }
        $s = $this->settings();
        if (! $s->exists || ! $s->allows($channel)) {
            throw ValidationException::withMessages(['channel' => __('referral.channel_disabled')]);
        }
        if (! in_array($jobRoleId, $s->job_role_ids ?? [], true)) {
            throw ValidationException::withMessages(['job_role_id' => __('referral.position_not_open')]);
        }
        $today = Referral::where('company_id', $this->company->id)->where('referrer_user_id', $this->actor->id)->where('created_at', '>', now()->subDay())->count();
        if ($today >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages(['limit' => __('referral.daily_limit', ['n' => self::DAILY_LIMIT])]);
        }
    }

    private function assertReviewable(Referral $ref): void
    {
        abort_unless((int) $ref->company_id === (int) $this->company->id && in_array($ref->status, array_merge(Referral::REVIEWABLE, [Referral::DECLINED, Referral::CONVERTED]), true) && ! $ref->anonymized_at, 404);
    }

    private function referrerEmployee(): ?Employee
    {
        return Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('user_id', $this->actor->id)->first();
    }

    private function mailInfoRequest(Referral $ref, string $url): bool
    {
        if (! $ref->email || ! TransactionalMailChannel::available()) {
            return false;
        }
        try {
            $locale = MailLocale::forCompany($this->company);
            Notification::route('mail', $ref->email)->notifyNow((new \App\Notifications\Referral\ReferralInfoRequestNotification($this->company->name, $url))->locale($locale));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function url(string $raw): string
    {
        return rtrim(config('app.url'), '/').'/referral#t='.$raw;
    }

    private function audit(string $action, ?Referral $ref, array $meta = []): void
    {
        app(AuditLogger::class)->logCompany($this->company->id, $action, 'referral', $ref ? (string) $ref->id : 'settings', [
            'actorId' => $this->actor?->id, 'actorType' => $this->actor ? 'user' : 'system', 'metadata' => $meta,
        ]);
    }

    // ── Candidate (public portal) ────────────────────────────────────────

    public static function byToken(string $raw): ?Referral
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return null;
        }
        $ref = Referral::withoutGlobalScopes()->where('token_hash', hash('sha256', $raw))->first();
        // Le module Parrainage de la société du lien est vérifié à CHAQUE appel public (pas de contexte tenant ici).
        $company = $ref ? Company::find($ref->company_id) : null;

        return $ref && $ref->linkOpen() && $company && \App\Core\Modules\ModuleGate::isActive($company, 'workforce_referral') ? $ref : null;
    }

    public static function portalView(Referral $ref): array
    {
        $company = Company::find($ref->company_id);
        $job = $ref->job_role_id ? JobRole::withoutGlobalScopes()->find($ref->job_role_id) : null;

        return ['company' => $company?->name, 'position' => $job?->title, 'description' => $job?->description,
            'mode' => $ref->status === Referral::INFO_REQUESTED ? 'info' : 'apply',
            'info_request' => $ref->status === Referral::INFO_REQUESTED ? $ref->info_request : null,
            // Recommandation : le prénom saisi par le parrain est proposé, le candidat le corrige s'il le faut.
            'prefill' => $ref->channel === Referral::CHANNEL_RECOMMENDATION || $ref->status === Referral::INFO_REQUESTED
                ? ['first_name' => $ref->first_name, 'last_name' => $ref->last_name, 'email' => $ref->email, 'phone' => $ref->phone] : null,
            'consent_text' => __('referral.consent_text', ['company' => $company?->name]), 'consent_version' => self::CONSENT_VERSION,
            'expires_at' => $ref->link_expires_at?->toIso8601String()];
    }

    /** The candidate applies (or completes after an info request) : explicit consent, single use. */
    public static function apply(Referral $ref, array $data): void
    {
        DB::transaction(function () use ($ref, $data) {
            $locked = Referral::withoutGlobalScopes()->whereKey($ref->id)->lockForUpdate()->first();
            if (! $locked->linkOpen()) {
                throw ValidationException::withMessages(['token' => __('referral.link_invalid')]);
            }
            $info = $locked->status === Referral::INFO_REQUESTED;
            $locked->forceFill([
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'candidate_message' => $data['message'] ?? $locked->candidate_message,
                'consent_at' => $info ? $locked->consent_at : now(), 'consent_version' => self::CONSENT_VERSION,
                'status' => $info ? Referral::IN_REVIEW : Referral::SUBMITTED, 'token_hash' => null, 'info_request' => $info ? null : $locked->info_request,
            ])->save();
        });
        $ref->refresh();
        app(AuditLogger::class)->logCompany($ref->company_id, 'referral.applied', 'referral', (string) $ref->id, ['actorType' => 'candidate']);
        $company = Company::find($ref->company_id);
        if ($company && ReferralSettings::for($company->id)->notify_reviewers) {
            $reviewers = User::whereIn('id', $company->memberships()->pluck('user_id'))->get()
                ->filter(fn (User $u) => $u->hasCompanyPermission($company, 'workforce_referral.review'));
            if ($reviewers->isNotEmpty()) {
                NotificationDispatcher::send(topicKey: 'workforce.referral_received', recipients: $reviewers->values(),
                    payload: ['position' => $ref->jobRole?->title, 'link' => '/company/workforce/referrals?open='.$ref->id], company: $company,
                    entityKey: 'referral:'.$ref->id);
            }
        }
    }

    /** Retention : closed referrals past their purge date are anonymised (history kept, identity removed). */
    public static function purgeDue(): int
    {
        $n = 0;
        Referral::withoutGlobalScopes()->whereNull('anonymized_at')
            ->where(fn ($q) => $q->where('purge_after', '<=', now())
                ->orWhere(fn ($w) => $w->whereIn('status', [Referral::LINK_SHARED, Referral::AWAITING_CONSENT])->where('link_expires_at', '<=', now()->subDays(30))))
            ->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $r) {
                    $r->forceFill(['first_name' => null, 'last_name' => null, 'referred_name' => null, 'email' => null, 'phone' => null, 'referrer_note' => null, 'candidate_message' => null,
                        'review_note' => null, 'info_request' => null, 'token_hash' => null, 'anonymized_at' => now(),
                        'status' => in_array($r->status, Referral::CANDIDATE_OPEN, true) ? Referral::EXPIRED : $r->status])->save();
                    $n++;
                }
            });

        return $n;
    }
}
