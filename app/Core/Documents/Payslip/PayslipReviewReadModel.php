<?php

namespace App\Core\Documents\Payslip;

use App\Core\Workforce\Employee;

/**
 * ADR-762 (DMS M5) — centre de revue d'un lot. N'expose QUE les métadonnées de rapprochement
 * (matricule/nom/période), jamais de données salariales. Pagination serveur.
 */
class PayslipReviewReadModel
{
    public static function summary(PayslipImportBatch $batch): array
    {
        $items = $batch->items()->get();

        return [
            'batch' => [
                'id' => $batch->id, 'status' => $batch->status, 'period' => $batch->period,
                'items_total' => $items->count(), 'items_published' => $batch->items_published,
                'errors' => $batch->errors,
            ],
            'counts' => [
                'certain' => $items->where('match_confidence', PayslipImportItem::C_CERTAIN)->count(),
                'review' => $items->where('state', PayslipImportItem::S_REVIEW)->count(),
                'unidentified' => $items->where('match_confidence', PayslipImportItem::C_NONE)->count(),
                'duplicates' => $items->where('match_confidence', PayslipImportItem::C_AMBIGUOUS)->count(),
                'matched' => $items->where('state', PayslipImportItem::S_MATCHED)->count(),
                'excluded' => $items->where('state', PayslipImportItem::S_EXCLUDED)->count(),
                'published' => $items->where('state', PayslipImportItem::S_PUBLISHED)->count(),
                'failed' => $items->where('state', PayslipImportItem::S_FAILED)->count(),
            ],
        ];
    }

    public static function items(PayslipImportBatch $batch, int $page = 1, int $perPage = 50): array
    {
        $query = $batch->items()->orderBy('id');
        $total = $query->count();
        $rows = $query->forPage($page, $perPage)->get();

        $empIds = $rows->pluck('matched_employee_id')->filter()
            ->merge($rows->pluck('match_candidates')->filter()->flatten())->unique()->all();
        $employees = $empIds
            ? Employee::withoutGlobalScopes()->whereIn('id', $empIds)->get(['id', 'first_name', 'last_name', 'employee_number'])->keyBy('id')
            : collect();

        $name = fn ($e) => $e ? trim($e->first_name.' '.$e->last_name) : null;

        return [
            'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'items' => $rows->map(fn ($i) => [
                'id' => $i->id,
                'page_range' => $i->page_range,
                'extracted_matricule' => $i->extracted_matricule,
                'extracted_period' => $i->extracted_period,
                'extracted_name' => $i->extracted_name,
                'confidence' => $i->match_confidence,
                'state' => $i->state,
                'review_reason' => $i->review_reason,
                'confirmed' => (bool) $i->confirmed,
                'page_numbers' => $i->page_numbers,
                'matched_employee' => $name($employees->get($i->matched_employee_id)),
                'matched_employee_id' => $i->matched_employee_id,
                'candidates' => collect($i->match_candidates ?? [])->map(fn ($id) => ['id' => $id, 'name' => $name($employees->get($id))])->values(),
                'error' => $i->error,
            ])->values(),
        ];
    }
}
