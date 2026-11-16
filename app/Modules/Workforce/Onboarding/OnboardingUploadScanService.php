<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentType;
use App\Core\Documents\Scanning\FileScanner;
use App\Core\Documents\Scanning\FileScannerManager;
use App\Core\Documents\Scanning\ScanResult;
use App\Core\Documents\Services\PersonSubjectResolver;
use App\Core\Documents\Services\SubjectDocumentUploadService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload as Up;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-796.1 — analysis of ONE quarantined deposit. Idempotent (row lock, only `quarantined` is
 * processed), tenant-bound (the deposit's company only), fail-closed :
 *   - every file clean  → ingested through the single DMS deposit path (pending HR review), quarantine
 *     copy deleted ;
 *   - one file infected → refused, files deleted at once (nothing to keep), metadata kept ;
 *   - indeterminate     → stays quarantined (retried) until max attempts, then `scan_failed` (never usable,
 *     HR may request a new analysis or a new deposit).
 * The parsers of the DMS pipeline (image/PDF processing, OCR) only ever run on files analysed clean.
 */
final class OnboardingUploadScanService
{
    public function __construct(private ?FileScanner $scanner = null) {}

    public function process(int $uploadId): string
    {
        $scanner = $this->scanner ?? FileScannerManager::driver();

        return DB::transaction(function () use ($uploadId, $scanner) {
            $up = Up::whereKey($uploadId)->lockForUpdate()->first();
            if (! $up || $up->status !== Up::QUARANTINED) {
                return $up?->status ?? 'missing'; // déjà traité : rejouer ne change rien
            }
            $inv = Inv::where('company_id', $up->company_id)->find($up->onboarding_invitation_id);
            $disk = Storage::disk('quarantine');
            $verdict = ScanResult::clean($scanner->engine());
            foreach ($up->files as $f) {
                $r = $disk->exists($f['path']) ? $scanner->scan($disk->path($f['path'])) : ScanResult::error($scanner->engine(), 'file_missing');
                if (! $r->isClean()) {
                    $verdict = $r;
                    if ($r->verdict === ScanResult::INFECTED) {
                        break;
                    }
                }
            }
            $up->scan_attempts++;
            $up->scan_engine = $verdict->engine;
            $up->scanned_at = now();

            if ($verdict->verdict === ScanResult::INFECTED) {
                $up->forceFill(['status' => Up::INFECTED, 'scan_signature' => $verdict->detail, 'last_error' => null])->save();
                $this->deleteFiles($up);
                $this->audit($up, 'onboarding.upload_infected', ['engine' => $verdict->engine, 'signature' => $verdict->detail]);

                return $up->status;
            }
            if ($verdict->verdict === ScanResult::ERROR) {
                $final = $up->scan_attempts >= (int) config('documents.scanner.max_attempts', 5);
                $up->forceFill(['status' => $final ? Up::SCAN_FAILED : Up::QUARANTINED, 'last_error' => $verdict->detail])->save();
                if ($final) {
                    $this->audit($up, 'onboarding.upload_scan_failed', ['engine' => $verdict->engine, 'reason' => $verdict->detail]);
                }

                return $up->status;
            }
            if (! $inv || $inv->status === Inv::REVOKED || $inv->purged_at) {
                $up->forceFill(['status' => Up::SUPERSEDED, 'last_error' => 'file_closed'])->save();
                $this->deleteFiles($up);

                return $up->status;
            }
            try {
                $doc = DB::transaction(fn () => $this->ingest($up, $inv)); // savepoint : un échec n'annule pas le verdict
            } catch (\Throwable $e) {
                $final = $up->scan_attempts >= (int) config('documents.scanner.max_attempts', 5);
                $up->forceFill(['status' => $final ? Up::SCAN_FAILED : Up::QUARANTINED, 'last_error' => 'ingest_failed'])->save();
                report($e);

                return $up->status;
            }
            $up->forceFill(['status' => Up::INGESTED, 'member_document_id' => $doc->id, 'last_error' => null])->save();
            $this->deleteFiles($up);
            $this->audit($up, 'onboarding.upload_ingested', ['engine' => $verdict->engine, 'member_document_id' => $doc->id]);

            return $up->status;
        });
    }

    /** HR : new analysis of an indeterminate deposit (never of an infected one). */
    public function retry(Up $up): void
    {
        abort_unless($up->status === Up::SCAN_FAILED && ! $up->files_deleted_at, 422, __('onboarding.scan_retry_impossible'));
        $up->forceFill(['status' => Up::QUARANTINED, 'scan_attempts' => 0, 'last_error' => null])->save();
        $this->audit($up, 'onboarding.upload_scan_retried', []);
        dispatch(new Jobs\ScanOnboardingUploadJob($up->company_id, $up->id))->afterCommit();
    }

    private function ingest(Up $up, Inv $inv)
    {
        $company = Company::findOrFail($up->company_id);
        $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($inv->employee_id);
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        $type = DocumentType::where('code', $up->document_code)->firstOrFail();
        $subject = PersonSubjectResolver::resolve($company, $user, $employee)['canonical'];
        $disk = Storage::disk('quarantine');
        $files = array_map(fn ($f) => new UploadedFile($disk->path($f['path']), $f['name'], $f['mime'], null, true), $up->files);

        return app(SubjectDocumentUploadService::class)->store($company, $subject, $user, $type, $files, null, null, null);
    }

    public static function deleteFiles(Up $up): void
    {
        $disk = Storage::disk('quarantine');
        foreach ($up->files as $f) {
            if ($disk->exists($f['path'])) {
                $disk->delete($f['path']);
            }
        }
        $up->forceFill(['files_deleted_at' => now()])->save();
    }

    private function audit(Up $up, string $action, array $meta): void
    {
        app(AuditLogger::class)->logCompany($up->company_id, $action, 'onboarding_invitation', (string) $up->onboarding_invitation_id, [
            'actorType' => 'system', 'severity' => $action === 'onboarding.upload_infected' ? 'warning' : 'info',
            'metadata' => $meta + ['upload_id' => $up->id, 'document_code' => $up->document_code],
        ]);
    }
}
