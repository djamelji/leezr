<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Notifications\MailLocale;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\Services\PersonSubjectResolver;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\PersonFieldWriter;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload;
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;
use App\Core\Email\TransactionalMailChannel;
use App\Notifications\Onboarding\OnboardingInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-796 (L6) — HR side of onboarding files. One HR record per person and company (existing one reused,
 * otherwise an INACTIVE preparatory record without account, contract or permission). One open file per
 * HR record (row lock). The link token is shown ONCE and stored as a hash. Validation writes the values
 * the candidate provided through the canonical writer (PersonFieldWriter, context onboarding) — HR decides
 * per value (accept / keep the existing one) ; documents stay in the DMS review (never validated here).
 */
final class OnboardingInvitationService
{
    public function __construct(private Company $company, private User $actor) {}

    public function create(array $data): Inv
    {
        $job = isset($data['job_role_id']) ? JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($data['job_role_id']) : null;
        $snapshot = (new OnboardingPlanner($this->company))->snapshot($job, $data['items']);

        $inv = DB::transaction(function () use ($data, $job, $snapshot) {
            $employee = isset($data['employee_id'])
                ? Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('id', $data['employee_id'])->lockForUpdate()->firstOrFail()
                : $this->preparatoryRecord($data, $job);
            if (Inv::where('company_id', $this->company->id)->where('employee_id', $employee->id)->whereNotIn('status', Inv::FINAL)->exists()) {
                throw ValidationException::withMessages(['employee_id' => __('onboarding.already_open')]);
            }

            return Inv::create([
                'company_id' => $this->company->id, 'employee_id' => $employee->id, 'job_role_id' => $job?->id, 'status' => Inv::DRAFT,
                'candidate_email' => $data['candidate_email'] ?? $employee->email, 'snapshot' => $snapshot, 'snapshot_version' => 1,
                'purpose' => $data['purpose'], 'legal_basis' => $data['legal_basis'] ?? null, 'notice_text' => $data['notice_text'] ?? null,
                'notice_version' => isset($data['notice_text']) ? substr(hash('sha256', $data['notice_text']), 0, 12) : null,
                'retention_days' => $data['retention_days'] ?? 30, 'created_by' => $this->actor->id,
            ]);
        });
        $this->audit($inv, 'onboarding.created');

        return $inv;
    }

    /** @return string the link, shown ONCE (fragment : never sent to a server, never in a Referer) */
    public function issueLink(Inv $inv, int $days): string
    {
        $this->assertOwned($inv);
        // ADR-796.1 : un dossier EXPIRÉ se renouvelle (nouveau lien) ; validé, révoqué ou soumis : jamais.
        if (in_array($inv->status, [Inv::VALIDATED, Inv::REVOKED, Inv::SUBMITTED], true) || $inv->purged_at) {
            throw ValidationException::withMessages(['status' => __('onboarding.not_sendable')]);
        }
        if (! $inv->legal_basis || ! $inv->notice_text) {
            throw ValidationException::withMessages(['legal_basis' => __('onboarding.declaration_required')]);
        }
        if (! $inv->candidate_email && collect($inv->snapshot['items'] ?? [])->contains(fn ($i) => $i['sensitive'] ?? false)) {
            // ADR-796.1 : les éléments sensibles exigent un second canal (code à usage unique).
            throw ValidationException::withMessages(['candidate_email' => __('onboarding.email_required_for_sensitive')]);
        }
        $raw = bin2hex(random_bytes(32));
        DB::transaction(function () use ($inv, $raw, $days) {
            Inv::where('id', $inv->id)->lockForUpdate()->first();
            $inv->sessions()->delete(); // un nouveau lien invalide l'ancien et ses sessions
            $inv->forceFill(['token_hash' => hash('sha256', $raw), 'expires_at' => now()->addDays(max(1, min(30, $days))),
                'status' => match ($inv->status) { Inv::DRAFT => Inv::SENT, Inv::EXPIRED => $inv->started_at ? Inv::IN_PROGRESS : Inv::SENT, default => $inv->status },
                'expired_at' => null, 'sent_at' => now(), 'open_attempts' => 0,
                'code_hash' => null, 'code_expires_at' => null, 'code_attempts' => 0, 'code_failures' => 0, 'verification_locked_at' => null])->save();
        });
        $this->audit($inv, 'onboarding.link_issued'); // jamais le jeton

        return rtrim(config('app.url'), '/').'/onboarding#t='.$raw;
    }

    /**
     * ADR-818 — ON-SITE mode : HR (authenticated, workforce.manage) opens THE SAME portal on its own device for
     * the person in front of it. Same file, same items, same quarantine, same review — no second engine. The
     * identity is checked in person by HR (session verified, audited with the actor) ; an e-mail is therefore
     * not required. An existing remote link keeps working (the file can be continued remotely and vice versa).
     *
     * @return string raw session (HttpOnly cookie, never shown)
     */
    public function openOnSite(Inv $inv): string
    {
        $this->assertOwned($inv);
        if (in_array($inv->status, [Inv::VALIDATED, Inv::REVOKED, Inv::SUBMITTED], true) || $inv->purged_at) {
            throw ValidationException::withMessages(['status' => __('onboarding.not_sendable')]);
        }
        if (! $inv->legal_basis || ! $inv->notice_text) {
            throw ValidationException::withMessages(['legal_basis' => __('onboarding.declaration_required')]);
        }
        $raw = bin2hex(random_bytes(32));
        DB::transaction(function () use ($inv, $raw) {
            Inv::where('id', $inv->id)->lockForUpdate()->first();
            $expires = $inv->expires_at && $inv->expires_at->isFuture() ? $inv->expires_at : now()->addDay();
            $inv->forceFill(['expires_at' => $expires, 'expired_at' => null, 'started_at' => $inv->started_at ?? now(),
                'status' => in_array($inv->status, [Inv::DRAFT, Inv::SENT, Inv::EXPIRED], true) ? Inv::IN_PROGRESS : $inv->status])->save();
            \App\Modules\Workforce\Onboarding\Models\OnboardingSession::create(['onboarding_invitation_id' => $inv->id, 'session_hash' => hash('sha256', $raw),
                'expires_at' => now()->addMinutes(OnboardingPortalService::SESSION_MINUTES)->min($expires), 'verified_at' => now()]);
        });
        $this->audit($inv, 'onboarding.on_site_opened', ['identity_checked_by' => $this->actor->id]);

        return $raw;
    }

    /**
     * ADR-818 — FINALISE a validated hire in one transaction : the preparatory record becomes an active
     * employee with its employment data (position, department, manager, start date). No account, no right,
     * no contract is created here : access (members.invite, anti-escalation) and contract (workforce.contracts)
     * stay their own explicit steps. Idempotent refusal once finalised.
     */
    public function finalize(Inv $inv, array $data): Employee
    {
        $this->assertOwned($inv);
        if ($inv->status !== Inv::VALIDATED) {
            throw ValidationException::withMessages(['status' => __('onboarding.finalize_requires_validation')]);
        }

        return DB::transaction(function () use ($inv, $data) {
            $employee = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->lockForUpdate()->findOrFail($inv->employee_id);
            if (! $employee->isPreparatory()) {
                throw ValidationException::withMessages(['status' => __('onboarding.already_finalized')]);
            }
            $metadata = (array) ($employee->metadata ?? []);
            $metadata['onboarding_finalized'] = ['at' => now()->toIso8601String(), 'by' => $this->actor->id, 'invitation_id' => $inv->id];
            $employee->forceFill([
                'status' => Employee::STATUS_ACTIVE,
                'hire_date' => $data['hire_date'],
                'job_role_id' => $data['job_role_id'] ?? $inv->job_role_id ?? $employee->job_role_id,
                'department_id' => $data['department_id'] ?? $employee->department_id,
                'manager_id' => $data['manager_id'] ?? $employee->manager_id,
                'metadata' => $metadata,
            ])->save();
            // ADR-818 (chantier C) : les pièces exigées et non encore déposées deviennent des demandes (même chaîne).
            $requested = \App\Core\Documents\Services\RequiredDocumentRequests::ensureForEmployee($this->company, $employee, $this->actor);
            $this->audit($inv, 'onboarding.finalized', ['employee_id' => $employee->id, 'requested' => $requested]);

            return $employee;
        });
    }

    /**
     * ADR-796.1 — e-mails the invitation (a NEW link : the previous one and its sessions die). Sent at once
     * through the transactional channel (never queued : the link never sits in a job payload) ; outcome
     * recorded (sent / failed, error without the link) ; cooldown + daily cap against repeated sends.
     */
    public function sendInvitation(Inv $inv, int $days): array
    {
        $this->assertOwned($inv);
        if (! $inv->candidate_email) {
            throw ValidationException::withMessages(['candidate_email' => __('onboarding.email_missing')]);
        }
        if (! TransactionalMailChannel::available()) {
            throw ValidationException::withMessages(['mail' => __('onboarding.mail_channel_unavailable')]);
        }
        DB::transaction(function () use ($inv) {
            $locked = Inv::whereKey($inv->id)->lockForUpdate()->first();
            if ($locked->mailed_at && $locked->mailed_at->gt(now()->subMinutes((int) config('onboarding.mail.cooldown_minutes', 2)))) {
                throw ValidationException::withMessages(['mail' => __('onboarding.mail_cooldown')]);
            }
            $windowOpen = $locked->mail_window_at && $locked->mail_window_at->gt(now()->subDay());
            if ($windowOpen && $locked->mail_count >= (int) config('onboarding.mail.max_per_day', 5)) {
                throw ValidationException::withMessages(['mail' => __('onboarding.mail_limit')]);
            }
            $locked->forceFill(['mailed_at' => now(), 'mail_count' => $windowOpen ? $locked->mail_count + 1 : 1, 'mail_window_at' => $windowOpen ? $locked->mail_window_at : now()])->save();
        });
        $url = $this->issueLink($inv->refresh(), $days);
        try {
            // ADR-813 — a candidate has no account : the e-mail speaks the language of the company's market.
            $locale = MailLocale::forCompany($this->company);
            Notification::route('mail', $inv->candidate_email)->notifyNow((new OnboardingInvitationNotification(
                $this->company->name, $url, $inv->refresh()->expires_at->locale($locale)->isoFormat('LL')))->locale($locale));
        } catch (\Throwable $e) {
            $error = Str::limit(preg_replace('#https?://\S+#', '[link]', trim(preg_replace('/\s+/', ' ', $e->getMessage()))) ?: class_basename($e), 250, '…');
            $inv->forceFill(['delivery_status' => 'failed', 'delivery_error' => $error])->save();
            $this->audit($inv, 'onboarding.invitation_delivery_failed', ['error' => $error]);

            return ['delivered' => false, 'error' => $error];
        }
        $inv->forceFill(['delivery_status' => 'sent', 'delivery_error' => null])->save();
        $this->audit($inv, 'onboarding.invitation_mailed');

        return ['delivered' => true];
    }

    /** ADR-796.1 — HR changes the candidate address : pending code dropped, link and sessions invalidated (re-send needed). */
    public function updateContact(Inv $inv, string $email): void
    {
        $this->assertOwned($inv);
        if (in_array($inv->status, Inv::FINAL, true)) {
            throw ValidationException::withMessages(['status' => __('onboarding.invalid_transition')]);
        }
        if (mb_strtolower((string) $inv->candidate_email) === mb_strtolower($email)) {
            return;
        }
        DB::transaction(function () use ($inv, $email) {
            Inv::whereKey($inv->id)->lockForUpdate()->first();
            $inv->sessions()->delete();
            $inv->forceFill(['candidate_email' => $email, 'token_hash' => null, 'code_hash' => null, 'code_expires_at' => null, 'code_attempts' => 0,
                'code_failures' => 0, 'verification_locked_at' => null, 'verified_at' => null, 'delivery_status' => null, 'delivery_error' => null])->save();
        });
        $this->audit($inv, 'onboarding.contact_changed'); // jamais l'adresse dans l'audit
    }

    /**
     * ADR-796.1 — what a revision would change, BEFORE confirming : added / removed / obligation changes,
     * answers already received that stop being requested (kept, never erased), consequences for the candidate.
     */
    public function previewRevision(Inv $inv, array $items): array
    {
        $this->assertOwned($inv);
        $job = $inv->job_role_id ? JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->find($inv->job_role_id) : null;
        $next = collect((new OnboardingPlanner($this->company))->snapshot($job, $items, $inv->snapshot_version + 1)['items'])->keyBy(fn ($i) => $i['kind'].':'.$i['code']);
        $current = collect($inv->snapshot['items'] ?? [])->keyBy(fn ($i) => $i['kind'].':'.$i['code']);
        $pick = fn ($c) => $c->map(fn ($i) => ['kind' => $i['kind'], 'code' => $i['code'], 'label' => $i['label'], 'required' => $i['required'], 'sensitive' => $i['sensitive']])->values()->all();
        $removed = $current->diffKeys($next);
        $received = $this->receivedKeys($inv);

        return [
            'added' => $pick($next->diffKeys($current)), 'removed' => $pick($removed),
            'required_changed' => $pick($next->filter(fn ($i, $k) => isset($current[$k]) && $current[$k]['required'] !== $i['required'])),
            'removed_with_answers' => array_values(array_intersect($removed->keys()->all(), $received)),
            'next_status' => $inv->status === Inv::SUBMITTED ? Inv::CHANGES_REQUESTED : $inv->status,
            'message_required' => $inv->status === Inv::SUBMITTED,
            'new_sensitive' => $next->diffKeys($current)->contains(fn ($i) => $i['sensitive']),
            'version' => $inv->snapshot_version + 1,
        ];
    }

    /** ADR-796.1 — HR asks for a new analysis of an indeterminate deposit. */
    public function retryScan(Inv $inv, int $uploadId): void
    {
        $this->assertOwned($inv);
        $up = OnboardingUpload::where('company_id', $this->company->id)->where('onboarding_invitation_id', $inv->id)->findOrFail($uploadId);
        app(OnboardingUploadScanService::class)->retry($up);
    }

    public function revoke(Inv $inv): void
    {
        $this->assertOwned($inv);
        if ($inv->status === Inv::VALIDATED) {
            throw ValidationException::withMessages(['status' => __('onboarding.invalid_transition')]);
        }
        $inv->sessions()->delete();
        $inv->forceFill(['status' => Inv::REVOKED, 'revoked_at' => now(), 'token_hash' => null])->save();
        $this->audit($inv, 'onboarding.revoked');
    }

    /** Explicit revision of what THIS file asks (previous version kept). */
    public function revise(Inv $inv, array $items, ?string $message = null): Inv
    {
        $this->assertOwned($inv);
        if (in_array($inv->status, Inv::FINAL, true)) {
            throw ValidationException::withMessages(['status' => __('onboarding.invalid_transition')]);
        }
        if ($inv->status === Inv::SUBMITTED && trim((string) $message) === '') {
            // Après soumission, une révision suit le circuit de demande de complément : la recrue est informée.
            throw ValidationException::withMessages(['message' => __('onboarding.revision_message_required')]);
        }
        $job = $inv->job_role_id ? JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->find($inv->job_role_id) : null;
        $snapshot = (new OnboardingPlanner($this->company))->snapshot($job, $items, $inv->snapshot_version + 1);
        $inv->forceFill(['snapshot_history' => array_merge($inv->snapshot_history ?? [], [$inv->snapshot]), 'snapshot' => $snapshot, 'snapshot_version' => $inv->snapshot_version + 1,
            'status' => $inv->status === Inv::SUBMITTED ? Inv::CHANGES_REQUESTED : $inv->status,
            'hr_message' => $inv->status === Inv::SUBMITTED ? mb_substr((string) $message, 0, 2000) : $inv->hr_message])->save();
        $this->audit($inv, 'onboarding.revised');

        return $inv;
    }

    public function requestChanges(Inv $inv, string $message): void
    {
        $this->assertOwned($inv);
        if ($inv->status !== Inv::SUBMITTED) {
            throw ValidationException::withMessages(['status' => __('onboarding.invalid_transition')]);
        }
        $inv->forceFill(['status' => Inv::CHANGES_REQUESTED, 'hr_message' => mb_substr($message, 0, 2000)])->save();
        $this->audit($inv, 'onboarding.changes_requested');
    }

    /**
     * @param  array<string, string>  $decisions  code => accept | keep
     */
    public function validate(Inv $inv, array $decisions, bool $confirmContractual): array
    {
        $this->assertOwned($inv);
        if ($inv->status !== Inv::SUBMITTED) {
            throw ValidationException::withMessages(['status' => __('onboarding.invalid_transition')]);
        }
        $blocking = $this->blockingUploads($inv);
        if ($blocking !== []) {
            // ADR-796.1 : aucune pièce en quarantaine, refusée ou à l'analyse indéterminée ne passe une validation.
            throw ValidationException::withMessages(['documents' => __('onboarding.documents_not_cleared', ['items' => implode(', ', $blocking)])]);
        }
        $employee = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($inv->employee_id);
        $allPending = OnboardingValue::where('onboarding_invitation_id', $inv->id)->where('status', OnboardingValue::PENDING)->get();
        $allCodes = FieldDefinition::whereIn('id', $allPending->pluck('field_definition_id'))->pluck('code', 'id');
        $requested = array_column($inv->items('field'), 'code');
        // Réponses à un élément retiré par révision : conservées, jamais écrites dans la fiche.
        $notRequested = $allPending->filter(fn ($v) => ! in_array($allCodes[$v->field_definition_id] ?? null, $requested, true));
        $pending = $allPending->diffKeys($notRequested);
        $codes = $allCodes;
        $missing = $pending->filter(fn ($v) => ! isset($decisions[$codes[$v->field_definition_id]]));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['decisions' => __('onboarding.decision_required')]);
        }

        return DB::transaction(function () use ($inv, $employee, $pending, $notRequested, $codes, $decisions, $confirmContractual) {
            Inv::where('id', $inv->id)->lockForUpdate()->first();
            $accept = [];
            foreach ($pending as $v) {
                if ($decisions[$codes[$v->field_definition_id]] === 'accept') {
                    $accept[$codes[$v->field_definition_id]] = $v->value;
                }
            }
            $written = $accept === [] ? ['changed' => []] : (new PersonFieldWriter(new EmployeeFieldStore($this->company)))->write(
                $employee, null, $accept, $this->actor, PersonFieldWriter::CONTEXT_ONBOARDING, $confirmContractual,
                ['purpose' => $inv->purpose, 'legal_basis' => $inv->legal_basis, 'notice_version' => $inv->notice_version, 'invitation_id' => $inv->id],
            );
            foreach ($notRequested as $v) {
                $v->forceFill(['status' => OnboardingValue::NOT_REQUESTED, 'decided_at' => now(), 'decided_by' => $this->actor->id])->save();
            }
            foreach ($pending as $v) {
                $v->forceFill(['status' => isset($accept[$codes[$v->field_definition_id]]) ? OnboardingValue::ACCEPTED : OnboardingValue::KEPT_EXISTING,
                    'decided_at' => now(), 'decided_by' => $this->actor->id])->save();
            }
            $inv->sessions()->delete();
            $inv->forceFill(['status' => Inv::VALIDATED, 'validated_at' => now(), 'validated_by' => $this->actor->id, 'token_hash' => null])->save();
            $this->audit($inv, 'onboarding.validated');

            return ['written' => $written['changed']];
        });
    }

    /** HR view : progress, staged values next to the canonical ones, DMS state of each document. */
    public function detail(Inv $inv, bool $canSensitive): array
    {
        $this->assertOwned($inv);
        $employee = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->find($inv->employee_id);
        $user = $employee?->user_id ? User::find($employee->user_id) : null;
        $cells = $employee ? (new EmployeeFieldStore($this->company))->cellsFor($employee, null) : [];
        $staged = OnboardingValue::where('onboarding_invitation_id', $inv->id)->get()->keyBy('field_definition_id');
        $defIds = FieldDefinition::whereIn('code', array_column($inv->items('field'), 'code'))->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))->pluck('id', 'code');
        $mask = fn ($item, $v) => ($item['sensitive'] && ! $canSensitive && $v !== null) ? '••••' : $v;
        $fields = array_map(function ($i) use ($cells, $staged, $defIds, $mask) {
            $s = $staged[$defIds[$i['code']] ?? 0] ?? null;
            $current = $cells[$i['code']]['value'] ?? null;

            return $i + ['provided' => $mask($i, $s?->value), 'current' => $mask($i, $current), 'decision' => $s?->status,
                'conflict' => $s && $current !== null && $current !== '' && (string) json_encode($current) !== (string) json_encode($s->value), 'filled' => $s && $s->value !== null && $s->value !== ''];
        }, $inv->items('field'));
        $subjects = PersonSubjectResolver::resolve($this->company, $user, $employee)['subjects'] ?? [];
        $requests = $subjects === [] ? collect() : DocumentRequest::where('company_id', $this->company->id)
            ->where(fn ($q) => collect($subjects)->each(fn ($sub) => $q->orWhere(fn ($w) => $w->where('document_subject_type', $sub->type)->where('document_subject_id', $sub->id))))
            ->get()->keyBy('document_type_id');
        $types = DocumentType::whereIn('code', array_column($inv->items('document'), 'code'))->pluck('id', 'code');
        $uploads = OnboardingUpload::where('onboarding_invitation_id', $inv->id)->where('status', '!=', OnboardingUpload::SUPERSEDED)->orderBy('id')->get()->keyBy('document_code');
        $documents = array_map(fn ($i) => $i + ['dms_status' => $requests[$types[$i['code']] ?? 0]->status ?? null,
            'scan' => ($u = $uploads[$i['code']] ?? null) ? ['upload_id' => $u->id, 'status' => $u->status, 'engine' => $u->scan_engine, 'scanned_at' => $u->scanned_at?->toIso8601String(),
                'signature' => $u->scan_signature, 'retryable' => $u->status === OnboardingUpload::SCAN_FAILED && ! $u->files_deleted_at] : null], $inv->items('document'));

        return ['id' => $inv->id, 'status' => self::effectiveStatus($inv), 'person_ref' => 'employee-'.$inv->employee_id, 'name' => trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
            'has_account' => $user !== null, 'expires_at' => $inv->expires_at?->toIso8601String(), 'snapshot_version' => $inv->snapshot_version,
            'purpose' => $inv->purpose, 'legal_basis' => $inv->legal_basis, 'hr_message' => $inv->hr_message, 'fields' => $fields, 'documents' => $documents,
            'submitted_at' => $inv->submitted_at?->toIso8601String(), 'validated_at' => $inv->validated_at?->toIso8601String(),
            'items' => $inv->snapshot['items'] ?? [], 'job_role_id' => $inv->job_role_id, 'has_email' => (bool) $inv->candidate_email,
            'delivery' => ['status' => $inv->delivery_status, 'error' => $inv->delivery_error, 'mailed_at' => $inv->mailed_at?->toIso8601String(), 'channel' => TransactionalMailChannel::available()],
            'verified_at' => $inv->verified_at?->toIso8601String(), 'verification_locked' => $inv->verification_locked_at !== null,
            'link_active' => $inv->token_hash !== null && $inv->isOpenForCandidate(), 'blocking_documents' => $this->blockingUploads($inv)];
    }

    /** @return list<string> document codes whose latest deposit is not cleared (quarantined / refused / indeterminate) */
    private function blockingUploads(Inv $inv): array
    {
        $latest = OnboardingUpload::where('onboarding_invitation_id', $inv->id)->where('status', '!=', OnboardingUpload::SUPERSEDED)->orderBy('id')->get()->keyBy('document_code');
        $requested = array_column($inv->items('document'), 'code');

        return $latest->filter(fn ($u, $code) => in_array($code, $requested, true) && in_array($u->status, OnboardingUpload::BLOCKING, true))->keys()->values()->all();
    }

    /** @return list<string> "kind:code" keys for which the candidate already provided something */
    private function receivedKeys(Inv $inv): array
    {
        $fields = FieldDefinition::whereIn('id', OnboardingValue::where('onboarding_invitation_id', $inv->id)->whereNotNull('value')->pluck('field_definition_id'))->pluck('code')
            ->map(fn ($c) => 'field:'.$c);
        $docs = OnboardingUpload::where('onboarding_invitation_id', $inv->id)->whereIn('status', [OnboardingUpload::QUARANTINED, OnboardingUpload::INGESTED])->pluck('document_code')
            ->map(fn ($c) => 'document:'.$c);

        return $fields->merge($docs)->unique()->values()->all();
    }

    /** Dashboard of the company : who was invited, started, missing, submitted, to review, expired. */
    public function index(): array
    {
        return array_map(fn ($r) => $r + ['bucket' => HiringFileReadModel::bucket($r)], $this->rows()); // ADR-819
    }

    private function rows(): array
    {
        // ADR-796.1 : une lecture reste une lecture. L'expiration est CONSTATÉE par OnboardingLifecycleService
        // (tâche planifiée) ; ici, un lien échu s'affiche « expiré » sans rien écrire.
        $invs = Inv::where('company_id', $this->company->id)->whereNull('purged_at')->latest('id')->limit(200)->get();
        $employees = Employee::withoutGlobalScopes()->whereIn('id', $invs->pluck('employee_id'))->with('jobRole:id,title')->get(['id', 'first_name', 'last_name', 'status', 'preparatory_at', 'job_role_id', 'hire_date'])->keyBy('id');
        // ADR-818 : avancement documentaire (dépôts) pour l'espace « Embauches ».
        $uploads = \App\Modules\Workforce\Onboarding\Models\OnboardingUpload::whereIn('onboarding_invitation_id', $invs->pluck('id'))
            ->where('status', '!=', \App\Modules\Workforce\Onboarding\Models\OnboardingUpload::SUPERSEDED)->get(['onboarding_invitation_id', 'document_code', 'status'])->groupBy('onboarding_invitation_id');
        $filled = OnboardingValue::whereIn('onboarding_invitation_id', $invs->pluck('id'))->whereNotNull('value')->selectRaw('onboarding_invitation_id, count(*) n')->groupBy('onboarding_invitation_id')->pluck('n', 'onboarding_invitation_id');

        return $invs->map(fn ($i) => ['id' => $i->id, 'status' => self::effectiveStatus($i), 'person_ref' => 'employee-'.$i->employee_id,
            'name' => trim(($employees[$i->employee_id]->first_name ?? '').' '.($employees[$i->employee_id]->last_name ?? '')),
            'fields_total' => count($i->items('field')), 'fields_filled' => (int) ($filled[$i->id] ?? 0), 'documents_total' => count($i->items('document')),
            'expires_at' => $i->expires_at?->toIso8601String(), 'submitted_at' => $i->submitted_at?->toIso8601String(),
            // ADR-818 — Embauches : poste, dépôts, pièces bloquées, finalisation (la fiche n'est plus préparatoire).
            'job_role' => $employees[$i->employee_id]?->jobRole?->title, 'hire_date' => $employees[$i->employee_id]?->hire_date?->toDateString(),
            'documents_deposited' => ($uploads[$i->id] ?? collect())->pluck('document_code')->unique()->count(),
            'documents_blocked' => ($uploads[$i->id] ?? collect())->whereIn('status', \App\Modules\Workforce\Onboarding\Models\OnboardingUpload::BLOCKING)->count(),
            'finalized' => isset($employees[$i->employee_id]) && ! $employees[$i->employee_id]->isPreparatory(),
            'updated_at' => $i->updated_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String()])->values()->all();
    }

    /** Status as displayed : a writable file past its expiry reads « expired » even before the scheduled task records it. */
    public static function effectiveStatus(Inv $inv): string
    {
        return in_array($inv->status, Inv::CANDIDATE_WRITABLE, true) && $inv->expires_at && $inv->expires_at->isPast() ? Inv::EXPIRED : $inv->status;
    }

    public function find(int $id): Inv
    {
        return Inv::where('company_id', $this->company->id)->findOrFail($id);
    }

    private function preparatoryRecord(array $data, ?JobRole $job): Employee
    {
        if (! empty($data['email']) && Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => __('onboarding.record_exists')]); // jamais une 2e fiche RH
        }

        return Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null, 'hire_date' => $data['hire_date'], 'status' => Employee::STATUS_INACTIVE, 'preparatory_at' => now(), 'job_role_id' => $job?->id, 'user_id' => null]);
    }

    private function assertOwned(Inv $inv): void
    {
        abort_unless((int) $inv->company_id === (int) $this->company->id, 404);
    }

    private function audit(Inv $inv, string $action, array $meta = []): void
    {
        app(AuditLogger::class)->logCompany($this->company->id, $action, 'onboarding_invitation', (string) $inv->id, [
            'actorId' => $this->actor->id, 'actorType' => 'user', 'metadata' => $meta + ['status' => $inv->status, 'snapshot_version' => $inv->snapshot_version],
        ]);
    }
}
