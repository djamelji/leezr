<?php

namespace App\Modules\Platform\Automations\Http;

use App\Core\Automation\AutomationRule;
use App\Core\Automation\AutomationRunLog;
use App\Core\Automation\AutomationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationRunner $runner,
    ) {}

    /**
     * List all automation rules with their latest 5 run logs.
     */
    public function index(): JsonResponse
    {
        $rules = AutomationRule::with(['runLogs' => function ($q) {
            $q->latest('created_at')->limit(5);
        }])
            ->orderBy('category')
            ->orderBy('key')
            ->get();

        return response()->json(['data' => $rules]);
    }

    /**
     * Update rule (enabled, schedule, config).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'schedule' => 'sometimes|string|max:100',
            'config' => 'sometimes|nullable|array',
        ]);

        $rule->update($validated);

        // Recalculate next_run_at if schedule changed
        if (isset($validated['schedule'])) {
            $rule->update([
                'next_run_at' => $this->runner->calculateNextRun($validated['schedule']),
            ]);
        }

        return response()->json([
            'data' => $rule->fresh()->load(['runLogs' => fn ($q) => $q->latest('created_at')->limit(5)]),
            'message' => 'Automation updated.',
        ]);
    }

    /**
     * Manually trigger a single rule.
     */
    public function run(Request $request, int $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);

        $result = $this->runner->runSingle($rule);

        return response()->json([
            'data' => $rule->fresh()->load(['runLogs' => fn ($q) => $q->latest('created_at')->limit(5)]),
            'result' => $result,
        ], $result['status'] === 'error' ? 422 : 200);
    }

    /**
     * Paginated run logs for a specific rule.
     */
    public function logs(int $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);

        $logs = AutomationRunLog::where('automation_rule_id', $rule->id)
            ->latest('created_at')
            ->paginate(20);

        return response()->json($logs);
    }
}
