<?php

namespace App\Modules\Platform\Support\Http;

use App\Modules\Platform\Support\SupportAssignees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-801.1 — read side of `assign_support` (also open to `manage_support`) : the minimal assignment queue
 * and the list of agents who can take a ticket. Nothing administrative here.
 */
class PlatformSupportAssignmentController
{
    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:open,in_progress,waiting_customer,resolved,closed',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json(SupportAssignees::queue($validated['status'] ?? null, $validated['search'] ?? null, (int) ($validated['per_page'] ?? 15)));
    }

    public function assignees(): JsonResponse
    {
        return response()->json(['assignees' => SupportAssignees::list()]);
    }
}
