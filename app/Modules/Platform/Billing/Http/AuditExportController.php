<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Audit\PlatformAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-311: Audit log export (JSON or CSV).
 *
 * Platform admin only. Max 10,000 entries per export.
 */
class AuditExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'in:json,csv',
            'action' => 'nullable|string',
        ]);

        $query = PlatformAuditLog::query()
            ->where('created_at', '>=', $request->input('start_date'))
            ->where('created_at', '<=', $request->input('end_date') . ' 23:59:59')
            ->orderBy('created_at', 'desc')
            ->limit(10000);

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        $format = $request->input('format', 'json');

        if ($format === 'csv') {
            return $this->streamCsv($query);
        }

        return response()->json($query->get());
    }

    private function streamCsv($query): StreamedResponse
    {
        $columns = ['id', 'actor_id', 'actor_type', 'action', 'target_type', 'target_id', 'severity', 'correlation_id', 'ip_address', 'created_at'];

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($logs) use ($handle, $columns) {
                foreach ($logs as $log) {
                    $row = [];
                    foreach ($columns as $col) {
                        $row[] = $log->{$col};
                    }
                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, 'audit-export-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
