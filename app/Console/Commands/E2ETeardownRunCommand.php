<?php

namespace App\Console\Commands;

use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Tenancy\CompanyContext;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ADR-770 (M7 LOT 4) — strictly bounded teardown of ONE end-to-end run (LOCAL/TESTING only).
 *
 * A browser run records, in its own ledger file, the exact ids of the rows it created (taken from
 * the API responses it received). This command deletes those rows and nothing else. Each row must
 * additionally PROVE it belongs to the run : it carries the run identifier (unique per execution)
 * and was created after the run started. A row that fails the proof is left untouched and reported.
 *
 * There is deliberately NO way to delete by a shared prefix, by a pattern, or without a ledger :
 * data left by another session, another run or a human can never be reached from here.
 */
class E2ETeardownRunCommand extends Command
{
    protected $signature = 'e2e:teardown-run {ledger : Path of the run ledger (JSON)} {--dry-run : Report only}';

    protected $description = 'Delete ONLY the rows recorded in one E2E run ledger, after an ownership proof (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $ledger = json_decode((string) @file_get_contents((string) $this->argument('ledger')), true);
        $runId = (string) ($ledger['run_id'] ?? '');
        if (! is_array($ledger) || ! preg_match('/^r[a-z0-9]{10,}$/', $runId) || empty($ledger['started_at'])) {
            $this->error('Refused: missing or malformed ledger (run_id / started_at).');

            return self::FAILURE;
        }
        $since = Carbon::parse($ledger['started_at'])->subSeconds(5);
        $dry = (bool) $this->option('dry-run');
        $report = ['deleted' => [], 'skipped' => []];

        $owned = fn (?string $marker, $createdAt) => $marker !== null && str_contains($marker, $runId)
            && $createdAt !== null && Carbon::parse($createdAt)->greaterThanOrEqualTo($since);

        // Tenant-bound : every company row is read and deleted INSIDE the company context the run
        // declared (CompanyScope active) — an id that belongs to another company is simply not found.
        $companyId = (int) ($ledger['company_id'] ?? 0);
        $records = collect($ledger['records'] ?? [])->groupBy('type');
        $ids = fn (string $type) => $records->get($type, collect())->pluck('id')->map(fn ($i) => (int) $i)->unique()->values()->all();

        if ($companyId > 0) {
            CompanyContext::runFor($companyId, function () use ($ids, $owned, $dry, &$report) {
                // Children first : contracts → employees → memberships.
                foreach ($ids('employee') as $id) {
                    $e = Employee::find($id);
                    if (! $e || ! $owned($e->last_name.' '.$e->email, $e->created_at)) {
                        $report['skipped'][] = "employee:{$id}";

                        continue;
                    }
                    if (! $dry) {
                        EmploymentContract::where('employee_id', $e->id)->delete();
                        // ADR-778 : the documents of an employee the run OWNS go with her (a run that
                        // uploads a piece for its own employee used to leave the piece and its request).
                        self::deleteSubjectDocuments($e->company_id, $e->id);
                        $e->forceDelete();
                    }
                    $report['deleted'][] = "employee:{$id}";
                }

                foreach ($ids('membership') as $id) {
                    $m = Membership::with('user')->find($id);
                    if (! $m || ! $owned($m->user?->email, $m->created_at)) {
                        $report['skipped'][] = "membership:{$id}";

                        continue;
                    }
                    $dry || $m->delete();
                    $report['deleted'][] = "membership:{$id}";
                }
            });
        } else {
            foreach (['employee', 'membership'] as $type) {
                foreach ($ids($type) as $id) {
                    $report['skipped'][] = "{$type}:{$id}";
                }
            }
        }

        // Accounts are global : an account that still belongs to ANY company (a membership this run
        // did not record) is not ours to delete.
        foreach ($ids('user') as $id) {
            $u = User::find($id);
            if (! $u || ! $owned($u->email, $u->created_at) || $u->memberships()->withoutGlobalScopes()->exists()) {
                $report['skipped'][] = "user:{$id}";

                continue;
            }
            $dry || $u->delete();
            $report['deleted'][] = "user:{$id}";
        }

        $this->line(json_encode(['run_id' => $runId, 'dry_run' => $dry] + $report));

        return self::SUCCESS;
    }

    /** Documents, versions, requests and request events whose SUBJECT is this (run-owned) employee. */
    private static function deleteSubjectDocuments(int $companyId, int $employeeId): void
    {
        $subject = fn ($q) => $q->where('company_id', $companyId)->where('document_subject_type', 'employee')->where('document_subject_id', $employeeId);
        $requests = \App\Core\Documents\DocumentRequest::withoutGlobalScopes()->tap($subject)->pluck('id');
        \App\Core\Documents\DocumentRequestEvent::whereIn('document_request_id', $requests)->delete();
        \App\Core\Documents\DocumentRequest::withoutGlobalScopes()->whereIn('id', $requests)->delete();
        foreach (\App\Core\Documents\MemberDocument::withoutGlobalScopes()->tap($subject)->get() as $doc) {
            if ($doc->file_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->file_path);
            }
            \App\Core\Documents\DocumentVersion::withoutGlobalScopes()->where('member_document_id', $doc->id)->delete();
            $doc->delete();
        }
    }
}
