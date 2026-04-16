<?php

namespace App\Core\Automation;

use App\Core\Automation\Jobs\ProcessWorkflowJob;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Support\Facades\Log;

/**
 * ADR-437: Evaluates domain events against company workflow rules.
 *
 * Called after every domain event is published. Finds matching workflow rules
 * for the event's topic + company, then dispatches async ProcessWorkflowJobs.
 *
 * This is the bridge between the realtime system and the workflow engine.
 */
final class WorkflowDispatcher
{
    /**
     * Evaluate an event envelope against all matching workflow rules.
     */
    public static function dispatch(EventEnvelope $envelope): void
    {
        // Only domain events can trigger workflows
        if ($envelope->category !== \App\Core\Realtime\EventCategory::Domain) {
            return;
        }

        // Company-scoped only
        if ($envelope->companyId === null) {
            return;
        }

        // Only process topics that have registered triggers
        if (! WorkflowTriggerRegistry::has($envelope->topic)) {
            return;
        }

        try {
            $rules = WorkflowRule::withoutCompanyScope()
                ->where('company_id', $envelope->companyId)
                ->forTrigger($envelope->topic)
                ->get();

            foreach ($rules as $rule) {
                if ($rule->canExecute()) {
                    ProcessWorkflowJob::dispatch(
                        $rule->id,
                        $envelope->topic,
                        $envelope->payload,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[workflow] dispatcher failed (non-blocking)', [
                'topic' => $envelope->topic,
                'company_id' => $envelope->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
