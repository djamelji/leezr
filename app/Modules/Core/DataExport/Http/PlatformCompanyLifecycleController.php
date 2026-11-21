<?php

namespace App\Modules\Core\DataExport\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\DataExport\CompanyDataExport;
use App\Core\Models\Company;
use App\Modules\Core\DataExport\CompanyDataExportService;
use App\Modules\Core\DataExport\CompanyLifecycleReadModel;
use Illuminate\Http\JsonResponse;

/**
 * ADR-845 (Leezr 4.2 M8) — Platform supervision of a company's end of relationship : state, obligations, exports
 * (status, size, expiry — never the archive itself) and revocation of an export link (manage_companies).
 */
class PlatformCompanyLifecycleController
{
    public function show(int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        return response()->json(CompanyLifecycleReadModel::for($company) + [
            'exports' => CompanyDataExport::with('requester:id,first_name,last_name')->where('company_id', $company->id)->latest('id')->limit(20)->get()->map->summary(),
        ]);
    }

    public function revoke(int $id, int $exportId, CompanyDataExportService $service): JsonResponse
    {
        $export = CompanyDataExport::where('company_id', $id)->findOrFail($exportId);
        $service->revoke($export);
        app(AuditLogger::class)->logPlatform(AuditAction::COMPANY_DATA_EXPORT_REVOKED, 'company_data_export', (string) $export->id, ['metadata' => ['company_id' => $id]]);
        app(AuditLogger::class)->logCompany($id, AuditAction::COMPANY_DATA_EXPORT_REVOKED, 'company_data_export', (string) $export->id, [
            'actorType' => 'platform', 'actorId' => null, 'metadata' => ['result' => 'revoked'],
        ]);

        return response()->json(['export' => $export->fresh()->summary()]);
    }
}
