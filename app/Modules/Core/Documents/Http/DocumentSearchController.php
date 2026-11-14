<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\Search\DocumentSearch;
use App\Core\Documents\Search\DocumentSearchQuery;
use App\Core\Home\HomeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-778 (UX3) — GET /api/company/documents/search : transversal, server-side, authorised, paginated. */
class DocumentSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $in = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:100'],
            'person' => ['nullable', 'regex:/^(employee|user)-\d+$/'],
            'status' => ['nullable', 'in:'.implode(',', DocumentSearchQuery::STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'expires_before' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.(int) ceil(DocumentSearch::WINDOW * 3 / DocumentSearch::PER_PAGE)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json(DocumentSearch::run(HomeContext::fromRequest($request), DocumentSearchQuery::fromArray($in), (int) ($in['page'] ?? 1), (int) ($in['per_page'] ?? DocumentSearch::PER_PAGE)));
    }
}
