<?php

namespace App\Modules\Core\Automations\Http;

use App\Core\Automation\WorkflowExecutionLog;
use App\Core\Automation\WorkflowRule;
use App\Core\Automation\WorkflowTriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-437: CRUD for company workflow rules.
 *
 * All queries auto-scoped by BelongsToCompany.
 */
class WorkflowRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = WorkflowRule::with('executionLogs:id,workflow_rule_id,status,created_at')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $rules,
            'triggers' => WorkflowTriggerRegistry::all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'trigger_topic' => 'required|string|max:255',
            'trigger_config' => 'nullable|array',
            'conditions' => 'nullable|array',
            'actions' => 'required|array|min:1',
            'enabled' => 'boolean',
            'max_executions_per_day' => 'integer|min:0|max:10000',
            'cooldown_minutes' => 'integer|min:0|max:1440',
        ]);

        $rule = WorkflowRule::create($validated);

        return response()->json(['data' => $rule], 201);
    }

    public function show(WorkflowRule $workflowRule): JsonResponse
    {
        $workflowRule->load('executionLogs');

        return response()->json(['data' => $workflowRule]);
    }

    public function update(Request $request, WorkflowRule $workflowRule): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'trigger_topic' => 'sometimes|string|max:255',
            'trigger_config' => 'nullable|array',
            'conditions' => 'nullable|array',
            'actions' => 'sometimes|array|min:1',
            'enabled' => 'boolean',
            'max_executions_per_day' => 'integer|min:0|max:10000',
            'cooldown_minutes' => 'integer|min:0|max:1440',
        ]);

        $workflowRule->update($validated);

        return response()->json(['data' => $workflowRule->fresh()]);
    }

    public function destroy(WorkflowRule $workflowRule): JsonResponse
    {
        $workflowRule->delete();

        return response()->json(null, 204);
    }

    /**
     * Execution history for a specific rule.
     */
    public function logs(WorkflowRule $workflowRule): JsonResponse
    {
        $logs = $workflowRule->executionLogs()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($logs);
    }

    /**
     * Available triggers for the workflow builder.
     */
    public function triggers(): JsonResponse
    {
        return response()->json([
            'data' => WorkflowTriggerRegistry::all(),
        ]);
    }
}
