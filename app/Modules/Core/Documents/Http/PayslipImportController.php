<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipImportItem;
use App\Core\Documents\Payslip\PayslipImportService;
use App\Core\Documents\Payslip\PayslipReviewReadModel;
use App\Core\Realtime\PublishesRealtimeEvents;
use App\Jobs\Documents\IngestPayslipBatchJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-762 (DMS M5) — import/revue/publication des bulletins externes. Chaque action a une permission
 * DISTINCTE (import/review/publish) vérifiée au niveau route ; la publication re-contrôle le sujet.
 */
class PayslipImportController extends Controller
{
    use PublishesRealtimeEvents;

    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $batches = PayslipImportBatch::where('company_id', $company->id)->orderByDesc('id')->get()
            ->map(fn ($b) => ['id' => $b->id, 'status' => $b->status, 'items_total' => $b->items_total, 'items_published' => $b->items_published, 'created_at' => $b->created_at?->toIso8601String()]);

        return response()->json(['batches' => $batches]);
    }

    public function store(Request $request, PayslipImportService $service): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:51200'], // 50 MB (la validation profonde est faite par le validateur)
        ]);
        $company = $request->attributes->get('company');

        $batch = $service->createBatch($company, $request->user(), array_values($request->file('files')));
        IngestPayslipBatchJob::dispatch($batch->id);

        return response()->json(['batch' => ['id' => $batch->id, 'status' => $batch->status]], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(PayslipReviewReadModel::summary($this->find($request, $id)));
    }

    public function items(Request $request, int $id): JsonResponse
    {
        $batch = $this->find($request, $id);
        $page = max(1, (int) $request->query('page', 1));

        return response()->json(PayslipReviewReadModel::items($batch, $page, min(100, (int) $request->query('per_page', 50))));
    }

    public function assign(Request $request, int $id, int $itemId, PayslipImportService $service): JsonResponse
    {
        $validated = $request->validate(['employee_id' => ['required', 'integer']]);
        $item = $this->findItem($request, $id, $itemId);
        $service->assign($item, $validated['employee_id'], $request->user());

        return response()->json(['message' => 'Match assigned.']);
    }

    public function exclude(Request $request, int $id, int $itemId, PayslipImportService $service): JsonResponse
    {
        $service->exclude($this->findItem($request, $id, $itemId));

        return response()->json(['message' => 'Item excluded.']);
    }

    public function previewItem(Request $request, int $id, int $itemId)
    {
        $item = $this->findItem($request, $id, $itemId);

        return Storage::disk('local')->download($item->file_path, 'segment.pdf');
    }

    // ADR-763 (M5.1) — éditeur de revue des frontières (regroupement / scission / exclusion / période).
    public function regroup(Request $request, int $id, PayslipImportService $service): JsonResponse
    {
        $v = $request->validate(['item_ids' => ['required', 'array', 'min:2'], 'item_ids.*' => ['integer']]);
        $this->find($request, $id); // garde tenant
        $items = \App\Core\Documents\Payslip\PayslipImportItem::where('company_id', $request->attributes->get('company')->id)
            ->where('payslip_import_batch_id', $id)->whereIn('id', $v['item_ids'])->pluck('id')->all();
        $service->regroup($items);

        return response()->json(['message' => 'Segments regrouped.']);
    }

    public function split(Request $request, int $id, int $itemId, PayslipImportService $service): JsonResponse
    {
        $v = $request->validate(['pages' => ['required', 'array', 'min:1'], 'pages.*' => ['integer']]);
        $service->split($this->findItem($request, $id, $itemId), $v['pages']);

        return response()->json(['message' => 'Segment split.']);
    }

    public function excludePage(Request $request, int $id, int $pageId, PayslipImportService $service): JsonResponse
    {
        $company = $request->attributes->get('company');
        $page = \App\Core\Documents\Payslip\PayslipImportPage::where('company_id', $company->id)
            ->where('payslip_import_batch_id', $id)->findOrFail($pageId);
        $service->excludePage($page);

        return response()->json(['message' => 'Page excluded.']);
    }

    public function correctPeriod(Request $request, int $id, int $itemId, PayslipImportService $service): JsonResponse
    {
        $v = $request->validate(['period' => ['required', 'string', 'max:7']]);
        $service->correctPeriod($this->findItem($request, $id, $itemId), $v['period']);

        return response()->json(['message' => 'Period corrected.']);
    }

    public function revoke(Request $request, int $payslipId, PayslipImportService $service): JsonResponse
    {
        $company = $request->attributes->get('company');
        $payslip = \App\Core\Documents\Payslip\PublishedPayslip::where('company_id', $company->id)->findOrFail($payslipId);
        $service->revoke($payslip, $request->user(), $request->input('reason'));

        return response()->json(['message' => 'Payslip revoked.']);
    }

    public function publish(Request $request, int $id, PayslipImportService $service): JsonResponse
    {
        $batch = $this->find($request, $id);
        $stats = $service->publishBatch($batch, $request->user());

        $this->publishDomainEvent('document.updated', $batch->company_id, ['type' => 'payslips_published', 'entity' => 'PayslipImportBatch', 'batch_id' => $batch->id]);

        return response()->json(['message' => 'Publication processed.', 'stats' => $stats]);
    }

    private function find(Request $request, int $id): PayslipImportBatch
    {
        $company = $request->attributes->get('company');

        return PayslipImportBatch::where('company_id', $company->id)->findOrFail($id);
    }

    private function findItem(Request $request, int $id, int $itemId): PayslipImportItem
    {
        $company = $request->attributes->get('company');

        return PayslipImportItem::where('company_id', $company->id)
            ->where('payslip_import_batch_id', $id)->findOrFail($itemId);
    }
}
