<?php

namespace App\Modules\Core\Documents\ReadModels;

use Illuminate\Support\Facades\DB;

/**
 * ADR-413: Batch ReadModel for AI document KPI widgets.
 *
 * Single query aggregation serving 4 dashboard widgets via datasetKey 'ai.document_kpis'.
 */
class DocumentAiKpiReadService
{
    public static function loadDataset(array $context): array
    {
        $period = $context['period'] ?? 'month';
        $since = self::periodToDate($period);

        $stats = DB::table('member_documents')
            ->where('created_at', '>=', $since)
            ->selectRaw("
                COUNT(*) as total_docs,
                COUNT(CASE WHEN ai_analysis IS NOT NULL THEN 1 END) as docs_analyzed,
                COUNT(CASE WHEN ai_analysis IS NOT NULL AND JSON_EXTRACT(ai_analysis, '$.confidence') >= 0.7 THEN 1 END) as high_confidence,
                COUNT(CASE WHEN ai_analysis IS NOT NULL AND JSON_EXTRACT(ai_analysis, '$.confidence') < 0.4 THEN 1 END) as low_confidence,
                COUNT(CASE WHEN JSON_EXTRACT(ai_analysis, '$.expiry_date') IS NOT NULL THEN 1 END) as expirations_detected,
                COUNT(CASE WHEN ai_insights IS NOT NULL AND JSON_CONTAINS(ai_insights, '\"auto_filled\"', '$[*].type') THEN 1 END) as auto_fills,
                COUNT(CASE WHEN ai_insights IS NOT NULL AND JSON_CONTAINS(ai_insights, '\"auto_rejected\"', '$[*].type') THEN 1 END) as auto_rejects,
                COUNT(CASE WHEN ai_insights IS NOT NULL AND JSON_CONTAINS(ai_insights, '\"type_mismatch\"', '$[*].type') THEN 1 END) as type_mismatches
            ")
            ->first();

        $docsAnalyzed = (int) ($stats->docs_analyzed ?? 0);
        $highConfidence = (int) ($stats->high_confidence ?? 0);

        return [
            'docs_analyzed' => $docsAnalyzed,
            'extraction_rate' => $docsAnalyzed > 0 ? round($highConfidence / $docsAnalyzed * 100) : 0,
            'low_confidence_count' => (int) ($stats->low_confidence ?? 0),
            'expirations_detected' => (int) ($stats->expirations_detected ?? 0),
            'type_mismatches' => (int) ($stats->type_mismatches ?? 0),
            'auto_fills' => (int) ($stats->auto_fills ?? 0),
            'auto_rejects' => (int) ($stats->auto_rejects ?? 0),
            'auto_actions_total' => (int) ($stats->auto_fills ?? 0) + (int) ($stats->auto_rejects ?? 0),
            'total_docs' => (int) ($stats->total_docs ?? 0),
        ];
    }

    private static function periodToDate(string $period): string
    {
        return match ($period) {
            'week' => now()->subWeek()->toDateString(),
            'quarter' => now()->subQuarter()->toDateString(),
            'year' => now()->subYear()->toDateString(),
            default => now()->subMonth()->toDateString(),
        };
    }
}
