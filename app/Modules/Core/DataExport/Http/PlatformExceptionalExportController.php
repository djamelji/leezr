<?php

namespace App\Modules\Core\DataExport\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\DataExport\CompanyDataExport;
use App\Core\Models\Company;
use App\Modules\Core\DataExport\CompanyDataExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-846 (Leezr 4.2 M8.1) — exceptional full data export when the company owner is unavailable. Permission
 * `exceptional_company_export`, mandatory justification and authority check, approval by a SECOND operator, audit on
 * both sides. The archive is delivered to a designated company member (downloaded from the Company app, signed in) :
 * there is NO Platform download route. Execution is OFF until the authority check is validated (409 on approval).
 */
class PlatformExceptionalExportController
{
    public function store(Request $request, int $id, CompanyDataExportService $service): JsonResponse
    {
        $company = Company::findOrFail($id);
        $min = (int) config('company_lifecycle.exceptional_export.min_justification_chars');
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer'],
            'justification' => ['required', 'string', "min:$min", 'max:2000'],
            'authority_check' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $export = $service->requestExceptional($company, $request->user('platform')->id, (int) $data['recipient_user_id'], $data['justification'], $data['authority_check']);
        $this->audit($export, AuditAction::COMPANY_DATA_EXPORT_EXCEPTIONAL_REQUESTED, ['recipient_user_id' => $export->recipient_user_id, 'justification' => $data['justification']]);

        return response()->json(['export' => $export->summary()], 201);
    }

    public function approve(Request $request, int $id, int $exportId, CompanyDataExportService $service): JsonResponse
    {
        $export = CompanyDataExport::where('company_id', $id)->findOrFail($exportId);
        $approved = $service->approveExceptional($export, $request->user('platform')->id);
        if ($approved === null) {
            $this->audit($export, AuditAction::COMPANY_DATA_EXPORT_EXCEPTIONAL_BLOCKED, ['reason' => 'execution_disabled']);

            return response()->json(['message' => __('companyExport.exceptional.disabled'), 'code' => 'exceptional_export_disabled'], 409);
        }
        $this->audit($approved, AuditAction::COMPANY_DATA_EXPORT_EXCEPTIONAL_APPROVED, ['requested_by_platform_user_id' => $approved->requested_by_platform_user_id]);

        return response()->json(['export' => $approved->fresh()->summary()]);
    }

    public function reject(Request $request, int $id, int $exportId, CompanyDataExportService $service): JsonResponse
    {
        $export = $service->rejectExceptional(CompanyDataExport::where('company_id', $id)->findOrFail($exportId), $request->user('platform')->id);
        $this->audit($export, AuditAction::COMPANY_DATA_EXPORT_EXCEPTIONAL_REJECTED, []);

        return response()->json(['export' => $export->summary()]);
    }

    private function audit(CompanyDataExport $export, string $action, array $meta): void
    {
        $logger = app(AuditLogger::class);
        $logger->logPlatform($action, 'company_data_export', (string) $export->id, ['severity' => 'critical', 'metadata' => ['company_id' => $export->company_id] + $meta]);
        $logger->logCompany($export->company_id, $action, 'company_data_export', (string) $export->id, ['actorType' => 'platform', 'actorId' => null, 'severity' => 'critical', 'metadata' => $meta]);
    }
}
