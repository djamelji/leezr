<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload;
use App\Modules\Workforce\Onboarding\OnboardingUploadScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-796.1 — TARGETED removal of acceptance-test fixtures of onboarding files, by EXPLICIT record ids
 * only (never by prefix or pattern). Dry run unless --apply. Refuses any record that is not a fictitious
 * preparatory fixture : name must start with "E2E ", inactive, no account, and NO operational trace
 * (contract, shift, time, leave, payroll… : any other table holding its employee_id). Removes the file(s),
 * staged values, quarantine, the documents / requests / HR values of THAT record, then the record.
 * Audit history is kept (one audit entry per removed record).
 */
class OnboardingCleanupFixturesCommand extends Command
{
    protected $signature = 'onboarding:cleanup-fixtures {employee* : explicit HR record ids} {--apply : actually remove}';

    protected $description = 'Remove explicitly listed onboarding acceptance-test fixtures (dry run by default)';

    public function handle(): int
    {
        $ids = array_map('intval', $this->argument('employee'));
        $employees = Employee::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
        $refusals = [];
        foreach ($ids as $id) {
            $e = $employees[$id] ?? null;
            $reason = match (true) {
                ! $e => 'not found',
                ! str_starts_with((string) $e->first_name, 'E2E ') => 'not a fictitious fixture',
                $e->status !== Employee::STATUS_INACTIVE || $e->user_id !== null => 'not an inactive record without account',
                ($t = $this->operationalTrace($e->id)) !== null => "operational trace in {$t}",
                default => null,
            };
            if ($reason) {
                $refusals[$id] = $reason;
            }
        }
        foreach ($refusals as $id => $r) {
            $this->error("#{$id} refused : {$r}");
        }
        if ($refusals !== []) {
            return self::FAILURE; // tout ou rien
        }
        foreach ($employees as $e) {
            $this->line("#{$e->id} {$e->first_name} {$e->last_name} (company {$e->company_id}) : ".json_encode($this->inventory($e)));
        }
        if (! $this->option('apply')) {
            $this->line('Dry run — nothing removed. Add --apply.');

            return self::SUCCESS;
        }
        foreach ($employees as $e) {
            DB::transaction(fn () => $this->remove($e));
            app(AuditLogger::class)->logCompany($e->company_id, 'onboarding.fixture_removed', 'employee', (string) $e->id, ['actorType' => 'system', 'metadata' => ['command' => 'onboarding:cleanup-fixtures']]);
        }
        $this->line('Removed: '.$employees->count());

        return self::SUCCESS;
    }

    private function operationalTrace(int $employeeId): ?string
    {
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if (in_array($name, ['onboarding_invitations', 'workforce_employees'], true) || ! Schema::hasColumn($name, 'employee_id')) {
                continue;
            }
            if (DB::table($name)->where('employee_id', $employeeId)->exists()) {
                return $name;
            }
        }

        return null;
    }

    private function docs(Employee $e)
    {
        return MemberDocument::withoutGlobalScopes()->where('company_id', $e->company_id)->where('document_subject_type', 'employee')->where('document_subject_id', $e->id);
    }

    private function inventory(Employee $e): array
    {
        $inv = OnboardingInvitation::where('employee_id', $e->id)->pluck('id');

        return ['files' => $inv->count(), 'values' => DB::table('onboarding_values')->whereIn('onboarding_invitation_id', $inv)->count(),
            'deposits' => OnboardingUpload::whereIn('onboarding_invitation_id', $inv)->count(), 'documents' => $this->docs($e)->count(),
            'requests' => DocumentRequest::withoutGlobalScopes()->where('company_id', $e->company_id)->where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->count(),
            'hr_values' => DB::table('field_values')->where('model_type', 'employee')->where('model_id', $e->id)->count()];
    }

    private function remove(Employee $e): void
    {
        $inv = OnboardingInvitation::where('employee_id', $e->id)->pluck('id');
        foreach (OnboardingUpload::whereIn('onboarding_invitation_id', $inv)->whereNull('files_deleted_at')->get() as $up) {
            OnboardingUploadScanService::deleteFiles($up);
        }
        foreach ($this->docs($e)->get() as $d) {
            foreach (DocumentVersion::where('member_document_id', $d->id)->get() as $v) {
                $v->file_path && Storage::disk('local')->delete($v->file_path);
                $v->delete();
            }
            $d->file_path && Storage::disk('local')->delete($d->file_path);
            $d->delete();
        }
        DocumentRequest::withoutGlobalScopes()->where('company_id', $e->company_id)->where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->delete();
        DB::table('field_value_changes')->where('model_type', 'employee')->where('model_id', $e->id)->delete();
        DB::table('field_values')->where('model_type', 'employee')->where('model_id', $e->id)->delete();
        DB::table('person_field_disclosures')->whereIn('onboarding_invitation_id', $inv)->delete();
        OnboardingInvitation::whereIn('id', $inv)->delete(); // values, sessions, deposits : cascade
        $e->delete();
    }
}
