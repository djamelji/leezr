<?php

namespace App\Core\Automation\Jobs;

use App\Core\Automation\ActionExecutor;
use App\Core\Automation\ConditionEvaluator;
use App\Core\Automation\WorkflowExecutionLog;
use App\Core\Automation\WorkflowRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ADR-437: Async job that evaluates and executes a workflow rule.
 *
 * Dispatched when a domain event matches a registered workflow trigger.
 * Always runs on the 'default' queue — never in the request lifecycle.
 */
class ProcessWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $workflowRuleId,
        public readonly string $triggerTopic,
        public readonly array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $startTime = hrtime(true);

        $rule = WorkflowRule::withoutCompanyScope()->find($this->workflowRuleId);
        if (! $rule || ! $rule->canExecute()) {
            return;
        }

        $conditionsMet = ConditionEvaluator::evaluate(
            $rule->conditions ?? [],
            $this->payload,
        );

        if (! $conditionsMet) {
            WorkflowExecutionLog::create([
                'workflow_rule_id' => $rule->id,
                'company_id' => $rule->company_id,
                'trigger_topic' => $this->triggerTopic,
                'trigger_payload' => $this->payload,
                'conditions_met' => false,
                'actions_executed' => [],
                'status' => 'skipped',
                'duration_ms' => (int) ((hrtime(true) - $startTime) / 1e6),
            ]);

            return;
        }

        $context = [
            'company_id' => $rule->company_id,
            'trigger_topic' => $this->triggerTopic,
            'payload' => $this->payload,
            'rule_id' => $rule->id,
        ];

        $results = ActionExecutor::execute($rule->actions ?? [], $context);

        $hasFailure = collect($results)->contains('status', 'failed');

        $rule->recordExecution();

        WorkflowExecutionLog::create([
            'workflow_rule_id' => $rule->id,
            'company_id' => $rule->company_id,
            'trigger_topic' => $this->triggerTopic,
            'trigger_payload' => $this->payload,
            'conditions_met' => true,
            'actions_executed' => $results,
            'status' => $hasFailure ? 'partial' : 'success',
            'duration_ms' => (int) ((hrtime(true) - $startTime) / 1e6),
        ]);

        Log::info('[workflow] rule executed', [
            'rule_id' => $rule->id,
            'company_id' => $rule->company_id,
            'topic' => $this->triggerTopic,
            'status' => $hasFailure ? 'partial' : 'success',
        ]);
    }
}
