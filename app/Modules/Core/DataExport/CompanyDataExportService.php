<?php

namespace App\Modules\Core\DataExport;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\DataExport\CompanyDataExport;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Core\DataExport\Jobs\BuildCompanyDataExportJob;
use App\Notifications\DataExport\CompanyDataExportReadyNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ADR-845 (Leezr 4.2 M8) — lifecycle of a full data export : request (one at a time, cooldown, under a row lock
 * on the company so two simultaneous requests cannot both pass), build (queued job of the existing queue
 * infrastructure — billing async untouched), expiry and pruning of the FILE, revocation by Platform.
 */
class CompanyDataExportService
{
    public function request(Company $company, User $user): CompanyDataExport
    {
        $export = DB::transaction(function () use ($company, $user) {
            Company::whereKey($company->id)->lockForUpdate()->first(); // serialises concurrent requests of one company
            // ADR-850 : a preparation older than the job's maximum duration is stale (worker lost) — it never blocks
            $busy = CompanyDataExport::where('company_id', $company->id)->whereIn('status', CompanyDataExport::IN_PROGRESS)
                ->where('created_at', '>', now()->subSeconds((new BuildCompanyDataExportJob(0))->timeout + 300))->exists();
            if ($busy) {
                throw ValidationException::withMessages(['export' => __('companyExport.already_running')]);
            }
            $cooldown = (int) config('company_lifecycle.export.cooldown_minutes');
            $recent = CompanyDataExport::where('company_id', $company->id)->where('created_at', '>', now()->subMinutes($cooldown))->exists();
            if ($recent) {
                throw ValidationException::withMessages(['export' => __('companyExport.cooldown', ['minutes' => $cooldown])]);
            }

            return CompanyDataExport::create(['company_id' => $company->id, 'requested_by_user_id' => $user->id, 'status' => CompanyDataExport::PENDING, 'disk' => 'local']);
        });

        app(AuditLogger::class)->logCompany($company->id, AuditAction::COMPANY_DATA_EXPORT_REQUESTED, 'company_data_export', (string) $export->id, [
            'severity' => 'warning', 'metadata' => ['result' => 'requested'],
        ]);
        BuildCompanyDataExportJob::dispatch($export->id);

        return $export->fresh();
    }

    /** run by the job ; a crash marks the export failed (never left « running » forever) */
    public function build(CompanyDataExport $export): void
    {
        $export->update(['status' => CompanyDataExport::RUNNING, 'started_at' => now()]);
        try {
            $result = app()->make(CompanyDataExportBuilder::class, ['company' => $export->company])->build($export);
            $status = ($result['manifest']['outcome'] ?? 'complete') === 'complete' ? CompanyDataExport::READY : CompanyDataExport::PARTIAL;
            $export->update(['status' => $status, 'path' => $result['path'], 'size_bytes' => $result['size'], 'manifest' => $result['manifest'],
                'finished_at' => now(), 'expires_at' => now()->addDays((int) config('company_lifecycle.export.ttl_days'))]);
            app(AuditLogger::class)->logCompany($export->company_id, AuditAction::COMPANY_DATA_EXPORT_READY, 'company_data_export', (string) $export->id, [
                'actorType' => 'system', 'actorId' => null,
                'metadata' => ['result' => $status, 'missing' => $result['manifest']['missing'], 'files' => count($result['manifest']['files']), 'errors' => count($result['manifest']['errors']), 'size_bytes' => $result['size']],
            ]);
            try {
                ($export->recipient ?? $export->requester)?->notify(new CompanyDataExportReadyNotification($export->company->name, $export->expires_at, $status === CompanyDataExport::PARTIAL));
            } catch (\Throwable $e) {
                Log::warning('[company-export] ready notice not sent', ['export' => $export->id, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            report($e);
            $export->update(['status' => CompanyDataExport::FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
            app(AuditLogger::class)->logCompany($export->company_id, AuditAction::COMPANY_DATA_EXPORT_FAILED, 'company_data_export', (string) $export->id, [
                'actorType' => 'system', 'actorId' => null, 'severity' => 'warning', 'metadata' => ['result' => 'failed'],
            ]);
        }
    }

    /** deletes the archive FILES whose link expired ; rows stay (status expired) as a trace. Returns files removed. */
    public function pruneExpired(): int
    {
        $removed = 0;
        CompanyDataExport::whereIn('status', CompanyDataExport::AVAILABLE)->where('expires_at', '<=', now())->each(function (CompanyDataExport $e) use (&$removed) {
            $this->dropFile($e);
            $e->update(['status' => CompanyDataExport::EXPIRED, 'path' => null]);
            $removed++;
        });

        return $removed;
    }

    public function revoke(CompanyDataExport $export): void
    {
        $this->dropFile($export);
        $export->update(['status' => CompanyDataExport::REVOKED, 'path' => null, 'expires_at' => now()]);
    }

    /**
     * ADR-846 — exceptional export, step 1 : an operator records WHY, HOW the representative's authority was checked,
     * and WHICH company member receives the archive (an active member, never the operator). Nothing is built yet.
     */
    public function requestExceptional(Company $company, int $operatorId, int $recipientUserId, string $justification, string $authorityCheck): CompanyDataExport
    {
        $recipient = User::find($recipientUserId);
        $membership = $recipient?->membershipFor($company);
        if (! $membership || $membership->isSuspended()) {
            throw ValidationException::withMessages(['recipient_user_id' => __('companyExport.exceptional.recipient_invalid')]);
        }

        return DB::transaction(function () use ($company, $operatorId, $recipientUserId, $justification, $authorityCheck) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            if (CompanyDataExport::where('company_id', $company->id)->whereIn('status', [CompanyDataExport::AWAITING_APPROVAL, ...CompanyDataExport::IN_PROGRESS])->exists()) {
                throw ValidationException::withMessages(['export' => __('companyExport.already_running')]);
            }

            return CompanyDataExport::create(['company_id' => $company->id, 'status' => CompanyDataExport::AWAITING_APPROVAL, 'disk' => 'local',
                'origin' => CompanyDataExport::ORIGIN_EXCEPTIONAL, 'recipient_user_id' => $recipientUserId, 'requested_by_platform_user_id' => $operatorId,
                'justification' => $justification, 'authority_check' => $authorityCheck]);
        });
    }

    /**
     * ADR-846 — step 2 : a SECOND operator approves. Refused to the requester himself, and refused while execution is
     * disabled (config company_lifecycle.exceptional_export.enabled — OFF until the authority check is validated).
     * Returns null when execution is disabled (nothing changes).
     */
    public function approveExceptional(CompanyDataExport $export, int $operatorId): ?CompanyDataExport
    {
        if ($export->status !== CompanyDataExport::AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['export' => __('companyExport.exceptional.not_awaiting')]);
        }
        if ($export->requested_by_platform_user_id === $operatorId) {
            throw ValidationException::withMessages(['export' => __('companyExport.exceptional.same_operator')]);
        }
        if (! config('company_lifecycle.exceptional_export.enabled')) {
            return null;
        }
        $export->update(['status' => CompanyDataExport::PENDING, 'approved_by_platform_user_id' => $operatorId, 'approved_at' => now()]);
        BuildCompanyDataExportJob::dispatch($export->id);

        return $export->fresh();
    }

    public function rejectExceptional(CompanyDataExport $export, int $operatorId): CompanyDataExport
    {
        if ($export->status !== CompanyDataExport::AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['export' => __('companyExport.exceptional.not_awaiting')]);
        }
        $export->update(['status' => CompanyDataExport::REJECTED, 'approved_by_platform_user_id' => $operatorId, 'approved_at' => now()]);

        return $export->fresh();
    }

    private function dropFile(CompanyDataExport $e): void
    {
        if ($e->path && str_starts_with($e->path, 'company-exports/'.$e->company_id.'/')) {
            Storage::disk($e->disk)->delete($e->path);
        }
    }
}
