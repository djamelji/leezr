<?php

namespace App\Modules\Core\DataExport\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\SubscriptionCanceller;
use App\Core\DataExport\CompanyDataExport;
use App\Modules\Core\DataExport\CompanyDataExportService;
use App\Modules\Core\DataExport\CompanyLifecycleReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-845 (Leezr 4.2 M8) — Company side of the end of relationship : where the company stands, its full data
 * exports (OWNER only, password re-confirmed to request ; temporary, audited download) and undoing a scheduled
 * cancellation. Cancelling itself stays the existing Billing endpoint (cancel-preview → cancel).
 */
class CompanyLifecycleController
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $owner = $request->user()->isOwnerOf($company);

        return response()->json(CompanyLifecycleReadModel::for($company) + [
            'can_export' => $owner,
            // ADR-846 : the owner sees every export ; another member only the exceptional exports delivered to them
            'exports' => CompanyDataExport::with('requester:id,first_name,last_name', 'recipient:id,first_name,last_name')->where('company_id', $company->id)
                ->when(! $owner, fn ($q) => $q->where('recipient_user_id', $request->user()->id)->whereIn('status', [...CompanyDataExport::AVAILABLE, CompanyDataExport::EXPIRED, CompanyDataExport::REVOKED]))
                ->latest('id')->limit(10)->get()->map->summary(),
        ]);
    }

    public function requestExport(Request $request, CompanyDataExportService $service): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->isOwnerOf($company), 403, __('companyExport.owner_only'));
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('password'), $request->user()->getAuthPassword())) {
            return response()->json(['message' => __('companyExport.password_invalid'), 'errors' => ['password' => [__('companyExport.password_invalid')]]], 422);
        }

        return response()->json(['export' => $service->request($company, $request->user())->summary()], 202);
    }

    public function download(Request $request, int $id)
    {
        $company = $request->attributes->get('company');
        $export = CompanyDataExport::where('company_id', $company->id)->findOrFail($id); // another company's id → 404
        abort_unless($export->isDeliveredTo($request->user(), $company), 403, __('companyExport.owner_only'));
        if (! $export->isDownloadable()) {
            return response()->json(['message' => __('companyExport.expired'), 'reason' => 'expired'], 410);
        }
        $export->increment('download_count');
        $export->update(['downloaded_at' => now()]);
        app(AuditLogger::class)->logCompany($company->id, AuditAction::COMPANY_DATA_EXPORT_DOWNLOADED, 'company_data_export', (string) $export->id, [
            'severity' => 'warning', 'metadata' => ['result' => 'downloaded', 'size_bytes' => $export->size_bytes, 'status' => $export->status, 'origin' => $export->origin, 'by_recipient' => ! $request->user()->isOwnerOf($company)],
        ]);

        return Storage::disk($export->disk)->download($export->path, 'export-'.$company->slug.'-'.$export->created_at->format('Ymd').'.zip', ['Cache-Control' => 'no-store']);
    }

    public function undoCancel(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $subscription = SubscriptionCanceller::undoScheduled($company);
        if (! $subscription) {
            return response()->json(['message' => __('companyExport.no_scheduled_cancellation')], 422);
        }
        app(AuditLogger::class)->logCompany($company->id, AuditAction::CANCEL_UNDONE, 'subscription', (string) $subscription->id, [
            'diffBefore' => ['cancel_at_period_end' => true], 'diffAfter' => ['cancel_at_period_end' => false], 'metadata' => ['source' => 'company'],
        ]);

        return response()->json(CompanyLifecycleReadModel::for($company));
    }
}
