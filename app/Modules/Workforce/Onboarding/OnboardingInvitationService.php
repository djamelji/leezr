<?php

namespace App\Modules\Workforce\Onboarding;

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
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;
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
        if (in_array($inv->status, Inv::FINAL, true) || $inv->status === Inv::SUBMITTED) {
            throw ValidationException::withMessages(['status' => __('onboarding.not_sendable')]);
        }
        if (! $inv->legal_basis || ! $inv->notice_text) {
            throw ValidationException::withMessages(['legal_basis' => __('onboarding.declaration_required')]);
        }
        $raw = bin2hex(random_bytes(32));
        DB::transaction(function () use ($inv, $raw, $days) {
            Inv::where('id', $inv->id)->lockForUpdate()->first();
            $inv->sessions()->delete(); // un nouveau lien invalide l'ancien et ses sessions
            $inv->forceFill(['token_hash' => hash('sha256', $raw), 'expires_at' => now()->addDays(max(1, min(30, $days))),
                'status' => $inv->status === Inv::DRAFT ? Inv::SENT : $inv->status, 'sent_at' => now(), 'open_attempts' => 0])->save();
        });
        $this->audit($inv, 'onboarding.link_issued'); // jamais le jeton

        return rtrim(config('app.url'), '/').'/onboarding#t='.$raw;
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
    public function revise(Inv $inv, array $items): Inv
    {
        $this->assertOwned($inv);
        if (in_array($inv->status, Inv::FINAL, true)) {
            throw ValidationException::withMessages(['status' => __('onboarding.invalid_transition')]);
        }
        $job = $inv->job_role_id ? JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->find($inv->job_role_id) : null;
        $snapshot = (new OnboardingPlanner($this->company))->snapshot($job, $items, $inv->snapshot_version + 1);
        $inv->forceFill(['snapshot_history' => array_merge($inv->snapshot_history ?? [], [$inv->snapshot]), 'snapshot' => $snapshot, 'snapshot_version' => $inv->snapshot_version + 1,
            'status' => $inv->status === Inv::SUBMITTED ? Inv::CHANGES_REQUESTED : $inv->status])->save();
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
        $employee = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($inv->employee_id);
        $pending = OnboardingValue::where('onboarding_invitation_id', $inv->id)->where('status', OnboardingValue::PENDING)->get();
        $codes = FieldDefinition::whereIn('id', $pending->pluck('field_definition_id'))->pluck('code', 'id');
        $missing = $pending->filter(fn ($v) => ! isset($decisions[$codes[$v->field_definition_id]]));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['decisions' => __('onboarding.decision_required')]);
        }

        return DB::transaction(function () use ($inv, $employee, $pending, $codes, $decisions, $confirmContractual) {
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
        $documents = array_map(fn ($i) => $i + ['dms_status' => $requests[$types[$i['code']] ?? 0]->status ?? null], $inv->items('document'));

        return ['id' => $inv->id, 'status' => $inv->status, 'person_ref' => 'employee-'.$inv->employee_id, 'name' => trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
            'has_account' => $user !== null, 'expires_at' => $inv->expires_at?->toIso8601String(), 'snapshot_version' => $inv->snapshot_version,
            'purpose' => $inv->purpose, 'legal_basis' => $inv->legal_basis, 'hr_message' => $inv->hr_message, 'fields' => $fields, 'documents' => $documents,
            'submitted_at' => $inv->submitted_at?->toIso8601String(), 'validated_at' => $inv->validated_at?->toIso8601String()];
    }

    /** Dashboard of the company : who was invited, started, missing, submitted, to review, expired. */
    public function index(): array
    {
        Inv::where('company_id', $this->company->id)->whereIn('status', Inv::CANDIDATE_WRITABLE)->where('expires_at', '<', now())
            ->update(['status' => Inv::EXPIRED, 'updated_at' => now()]); // expiration constatée (aucune donnée supprimée)
        $invs = Inv::where('company_id', $this->company->id)->whereNull('purged_at')->latest('id')->limit(200)->get();
        $employees = Employee::withoutGlobalScopes()->whereIn('id', $invs->pluck('employee_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $filled = OnboardingValue::whereIn('onboarding_invitation_id', $invs->pluck('id'))->whereNotNull('value')->selectRaw('onboarding_invitation_id, count(*) n')->groupBy('onboarding_invitation_id')->pluck('n', 'onboarding_invitation_id');

        return $invs->map(fn ($i) => ['id' => $i->id, 'status' => $i->status, 'person_ref' => 'employee-'.$i->employee_id,
            'name' => trim(($employees[$i->employee_id]->first_name ?? '').' '.($employees[$i->employee_id]->last_name ?? '')),
            'fields_total' => count($i->items('field')), 'fields_filled' => (int) ($filled[$i->id] ?? 0), 'documents_total' => count($i->items('document')),
            'expires_at' => $i->expires_at?->toIso8601String(), 'submitted_at' => $i->submitted_at?->toIso8601String()])->values()->all();
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
            'email' => $data['email'] ?? null, 'hire_date' => $data['hire_date'], 'status' => Employee::STATUS_INACTIVE, 'job_role_id' => $job?->id, 'user_id' => null]);
    }

    private function assertOwned(Inv $inv): void
    {
        abort_unless((int) $inv->company_id === (int) $this->company->id, 404);
    }

    private function audit(Inv $inv, string $action): void
    {
        app(AuditLogger::class)->logCompany($this->company->id, $action, 'onboarding_invitation', (string) $inv->id, [
            'actorId' => $this->actor->id, 'actorType' => 'user', 'metadata' => ['status' => $inv->status, 'snapshot_version' => $inv->snapshot_version],
        ]);
    }
}
