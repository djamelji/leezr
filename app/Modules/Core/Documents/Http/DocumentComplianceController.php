<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\ReadModels\DocumentActivityReadModel;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-387/396/423: Document compliance dashboard + activity + CSV export.
 *
 * Returns lifecycle-based compliance stats for the company:
 * summary KPIs, breakdown by role, breakdown by document type.
 * Also returns recent document activity from audit logs.
 * CSV export for compliance by type data.
 */
class DocumentComplianceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            DocumentComplianceReadModel::forCompany($company),
        );
    }

    public function activity(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'activity' => DocumentActivityReadModel::forCompany($company->id),
        ]);
    }

    /**
     * ADR-423: Export compliance by-type data as CSV.
     * UTF-8 BOM for Excel compatibility.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $company = $request->attributes->get('company');
        $data = DocumentComplianceReadModel::forCompany($company);
        $rows = $data['by_type'] ?? [];

        $filename = 'compliance-'.date('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM for Excel
            fwrite($out, "\xEF\xBB\xBF");

            // Header row
            fputcsv($out, [
                'type_code',
                'type_label',
                'scope',
                'total',
                'valid',
                'missing',
                'expiring',
                'expired',
                'compliance_rate',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['code'],
                    $row['label'],
                    $row['scope'],
                    $row['total'],
                    $row['valid'],
                    $row['missing'],
                    $row['expiring_soon'],
                    $row['expired'],
                    $row['rate'].'%',
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
