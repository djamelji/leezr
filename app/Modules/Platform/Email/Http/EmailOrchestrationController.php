<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailOrchestrationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailOrchestrationController
{
    public function index(): JsonResponse
    {
        $rules = EmailOrchestrationRule::with('template:key,name,category,is_active')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['rules' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trigger_event' => 'required|string|max:100',
            'template_key' => 'required|string|exists:email_templates,key',
            'timing' => 'required|in:immediate,delayed,recurring',
            'delay_value' => 'nullable|integer|min:0',
            'delay_unit' => 'nullable|in:days,hours,minutes',
            'frequency' => 'nullable|in:daily,every_2_days,every_3_days,weekly,bi_weekly,monthly',
            'max_sends' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['sort_order'] = EmailOrchestrationRule::max('sort_order') + 1;

        $rule = EmailOrchestrationRule::create($validated);

        return response()->json([
            'message' => 'Rule created.',
            'rule' => $rule->load('template:key,name,category,is_active'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = EmailOrchestrationRule::findOrFail($id);

        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'timing' => 'sometimes|in:immediate,delayed,recurring',
            'delay_value' => 'sometimes|integer|min:0',
            'delay_unit' => 'sometimes|in:days,hours,minutes',
            'max_sends' => 'sometimes|integer|min:0',
            'template_key' => 'sometimes|string|exists:email_templates,key',
            'frequency' => 'nullable|in:daily,every_2_days,every_3_days,weekly,bi_weekly,monthly',
        ]);

        $rule->update($validated);

        return response()->json(['message' => 'Rule updated.', 'rule' => $rule->fresh()->load('template:key,name,category,is_active')]);
    }
}
