<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\PersonSubjectResolver;
use App\Core\Documents\Services\SubjectDocumentUploadService;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingSession;
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ADR-796 (L6) — PUBLIC side, scoped to ONE file. Every call resolves the session (cookie, hash only) →
 * one invitation → one company → one HR record ; nothing else is reachable (no internal id accepted).
 *  - the link token is exchanged once for a short session (never re-sent, never in a URL path) ;
 *  - fields : only those of the snapshot, validated with the canonical rules, STAGED for HR review ;
 *  - sensitive fields / highly confidential documents need a VERIFIED session (one-time code sent to a
 *    separate channel, the e-mail given by HR) — a bearer link alone is not an identity proof ;
 *  - documents : only those of the snapshot, through the single DMS deposit path (pending review) ;
 *  - submission : explicit, idempotent ; expired / revoked / validated files accept no write.
 */
final class OnboardingPortalService
{
    public const SESSION_MINUTES = 120;
    public const CODE_MINUTES = 10;
    public const MAX_CODE_ATTEMPTS = 5;
    public const MAX_FILES = 5;

    public static function openSession(string $rawToken): ?string
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }
        $inv = Inv::where('token_hash', hash('sha256', $rawToken))->first();
        if (! $inv || ! $inv->isOpenForCandidate() || ! self::moduleActive($inv)) {
            return null; // même réponse pour inconnu, expiré, révoqué (aucune énumération)
        }
        $raw = bin2hex(random_bytes(32));
        OnboardingSession::create(['onboarding_invitation_id' => $inv->id, 'session_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->min($inv->expires_at)]);
        if (! $inv->started_at) {
            $inv->forceFill(['started_at' => now(), 'status' => $inv->status === Inv::SENT ? Inv::IN_PROGRESS : $inv->status])->save();
        }

        return $raw;
    }

    public static function fromSession(?string $raw): ?self
    {
        if (! $raw || ! preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return null;
        }
        $session = OnboardingSession::where('session_hash', hash('sha256', $raw))->where('expires_at', '>', now())->first();
        $inv = $session ? Inv::find($session->onboarding_invitation_id) : null;

        return $inv && $inv->isOpenForCandidate() && self::moduleActive($inv) ? new self($inv, $session) : null;
    }

    /** Le dossier ne vit que si le module Workforce est actif pour sa société (portail public non gaté par route). */
    private static function moduleActive(Inv $inv): bool
    {
        $company = Company::find($inv->company_id);

        return $company !== null && \App\Core\Modules\ModuleGate::isActive($company, 'workforce');
    }

    private function __construct(private Inv $inv, private OnboardingSession $session) {}

    private function company(): Company
    {
        return Company::findOrFail($this->inv->company_id);
    }

    private function employee(): Employee
    {
        return Employee::withoutGlobalScopes()->where('company_id', $this->inv->company_id)->findOrFail($this->inv->employee_id);
    }

    private function verified(): bool
    {
        return $this->session->verified_at !== null;
    }

    /** What the candidate sees — no internal identifier, no other person, no catalogue. */
    public function view(): array
    {
        $company = $this->company();
        $defs = $this->definitions();
        $staged = OnboardingValue::where('onboarding_invitation_id', $this->inv->id)->get()->keyBy('field_definition_id');
        $docs = $this->depositedCodes();
        $fields = array_map(function ($i) use ($defs, $staged) {
            $def = $defs[$i['code']] ?? null;
            $v = $def ? ($staged[$def->id]->value ?? null) : null;
            $hidden = $i['sensitive'] && ! $this->verified();

            return ['code' => $i['code'], 'label' => $i['label'], 'type' => $def?->type, 'options' => $def?->options, 'required' => $i['required'],
                'sensitive' => $i['sensitive'], 'locked' => $hidden, 'value' => $hidden ? null : $v, 'filled' => $v !== null && $v !== ''];
        }, $this->inv->items('field'));
        $documents = array_map(fn ($i) => ['code' => $i['code'], 'label' => $i['label'], 'required' => $i['required'], 'sensitive' => $i['sensitive'],
            'locked' => $i['sensitive'] && ! $this->verified(), 'deposited' => isset($docs[$i['code']]), 'file_name' => $docs[$i['code']] ?? null], $this->inv->items('document'));
        $missing = $this->missing($fields, $documents);

        return [
            'company' => ['name' => $company->name], 'status' => $this->inv->status, 'expires_at' => $this->inv->expires_at->toIso8601String(),
            'purpose' => $this->inv->purpose, 'legal_basis' => $this->inv->legal_basis, 'notice' => $this->inv->notice_text, 'hr_message' => $this->inv->hr_message,
            'writable' => in_array($this->inv->status, Inv::CANDIDATE_WRITABLE, true), 'verified' => $this->verified(),
            'verification_available' => (bool) $this->inv->candidate_email, 'fields' => $fields, 'documents' => $documents, 'missing' => $missing,
            'progress' => ['done' => count(array_filter($fields, fn ($f) => $f['filled'] || ($f['locked'] && $this->stagedFilled($f['code'])))) + count(array_filter($documents, fn ($d) => $d['deposited'])),
                'total' => count($fields) + count($documents)],
        ];
    }

    public function saveDraft(array $values): array
    {
        $this->assertWritable();
        $requested = collect($this->inv->items('field'))->keyBy('code');
        $defs = $this->definitions();
        $store = new EmployeeFieldStore($this->company());
        $rules = [];
        foreach ($values as $code => $v) {
            $item = $requested[$code] ?? null;
            if (! $item || ! isset($defs[$code])) {
                throw ValidationException::withMessages(["values.{$code}" => __('onboarding.not_requested')]);
            }
            if ($item['sensitive'] && ! $this->verified()) {
                throw ValidationException::withMessages(["values.{$code}" => __('onboarding.verification_required')]);
            }
            $rules["values.{$code}"] = $store->rulesFor($defs[$code]);
        }
        Validator::make(['values' => $values], $rules)->validate();
        DB::transaction(function () use ($values, $defs) {
            Inv::where('id', $this->inv->id)->lockForUpdate()->first();
            foreach ($values as $code => $v) {
                OnboardingValue::updateOrCreate(['onboarding_invitation_id' => $this->inv->id, 'field_definition_id' => $defs[$code]->id],
                    ['value' => $v === '' ? null : $v, 'status' => OnboardingValue::PENDING]);
            }
        });

        return $this->view();
    }

    public function upload(string $code, array $files): array
    {
        $this->assertWritable();
        $item = collect($this->inv->items('document'))->firstWhere('code', $code);
        $type = $item ? DocumentType::where('code', $code)->first() : null;
        if (! $item || ! $type || $type->scope !== DocumentType::SCOPE_COMPANY_USER) {
            abort(404); // jamais un type non demandé
        }
        if (DocumentConfidentiality::isHighlyConfidential($code) && ! $this->verified()) {
            throw ValidationException::withMessages(['files' => __('onboarding.verification_required')]);
        }
        $rule = SubjectDocumentUploadService::fileRules($type);
        Validator::make(['files' => $files], ['files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES], 'files.*' => $rule])->validate();
        $company = $this->company();
        $employee = $this->employee();
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        $subject = PersonSubjectResolver::resolve($company, $user, $employee)['canonical'];
        app(SubjectDocumentUploadService::class)->store($company, $subject, $user, $type, array_values($files), null, null, null);

        return $this->view();
    }

    public function submit(): array
    {
        if ($this->inv->status === Inv::SUBMITTED) {
            return $this->view(); // idempotent
        }
        $this->assertWritable();
        $view = $this->view();
        if ($view['missing']['required'] !== []) {
            throw ValidationException::withMessages(['missing' => __('onboarding.incomplete')]);
        }
        DB::transaction(function () {
            $locked = Inv::where('id', $this->inv->id)->lockForUpdate()->first();
            if ($locked->status !== Inv::SUBMITTED) {
                $locked->forceFill(['status' => Inv::SUBMITTED, 'submitted_at' => now(), 'hr_message' => null])->save();
            }
            $this->inv->refresh();
        });
        app(\App\Core\Audit\AuditLogger::class)->logCompany($this->inv->company_id, 'onboarding.submitted', 'onboarding_invitation', (string) $this->inv->id, ['actorType' => 'candidate']);

        return $this->view();
    }

    public function sendCode(): void
    {
        if (! $this->inv->candidate_email) {
            throw ValidationException::withMessages(['code' => __('onboarding.no_channel')]);
        }
        $code = (string) random_int(100000, 999999);
        $this->inv->forceFill(['code_hash' => hash('sha256', $this->inv->id.'|'.$code), 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'code_attempts' => 0])->save();
        $company = $this->company()->name;
        Mail::raw(__('onboarding.code_mail', ['code' => $code, 'company' => $company, 'minutes' => self::CODE_MINUTES]),
            fn ($m) => $m->to($this->inv->candidate_email)->subject(__('onboarding.code_subject', ['company' => $company])));
    }

    public function verify(string $code): bool
    {
        $inv = $this->inv;
        if (! $inv->code_hash || ! $inv->code_expires_at?->isFuture() || $inv->code_attempts >= self::MAX_CODE_ATTEMPTS) {
            return false;
        }
        if (! hash_equals($inv->code_hash, hash('sha256', $inv->id.'|'.$code))) {
            $inv->increment('code_attempts');

            return false;
        }
        $inv->forceFill(['code_hash' => null, 'code_expires_at' => null, 'code_attempts' => 0])->save(); // usage unique
        $this->session->forceFill(['verified_at' => now()])->save();

        return true;
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function assertWritable(): void
    {
        if (! in_array($this->inv->status, Inv::CANDIDATE_WRITABLE, true)) {
            throw ValidationException::withMessages(['status' => __('onboarding.read_only')]);
        }
    }

    /** @return \Illuminate\Support\Collection<string, FieldDefinition> */
    private function definitions()
    {
        return FieldDefinition::whereIn('code', array_column($this->inv->items('field'), 'code'))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->inv->company_id))->get()->keyBy('code');
    }

    /** @return array<string, string> code => file name, for the file's subject(s) only */
    private function depositedCodes(): array
    {
        $employee = $this->employee();
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        $subjects = PersonSubjectResolver::resolve($this->company(), $user, $employee)['subjects'];
        $codes = array_column($this->inv->items('document'), 'code');
        $out = [];
        foreach (MemberDocument::where('company_id', $this->inv->company_id)->whereNotNull('file_path')
            ->where(fn ($q) => collect($subjects)->each(fn ($s) => $q->orWhere(fn ($w) => $w->where('document_subject_type', $s->type)->where('document_subject_id', $s->id))))
            ->with('documentType:id,code')->get() as $d) {
            if (in_array($d->documentType?->code, $codes, true)) {
                $out[$d->documentType->code] = $d->file_name;
            }
        }

        return $out;
    }

    private function missing(array $fields, array $documents): array
    {
        $req = fn ($list, $done) => array_values(array_map(fn ($i) => $i['code'], array_filter($list, fn ($i) => $i['required'] && ! $done($i))));

        return ['required' => array_merge($req($fields, fn ($f) => $f['filled'] || ($f['locked'] && $this->stagedFilled($f['code']))), $req($documents, fn ($d) => $d['deposited']))];
    }

    private function stagedFilled(string $code): bool
    {
        $def = $this->definitions()[$code] ?? null;

        return $def && OnboardingValue::where('onboarding_invitation_id', $this->inv->id)->where('field_definition_id', $def->id)->whereNotNull('value')->exists();
    }
}
