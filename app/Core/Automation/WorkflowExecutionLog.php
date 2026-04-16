<?php

namespace App\Core\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-437: Execution log for company workflow rules.
 *
 * Audit trail: which rule fired, why, what actions executed, result.
 */
class WorkflowExecutionLog extends Model
{
    protected $fillable = [
        'workflow_rule_id',
        'company_id',
        'trigger_topic',
        'trigger_payload',
        'conditions_met',
        'actions_executed',
        'status',
        'error_message',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'trigger_payload' => 'array',
            'conditions_met' => 'boolean',
            'actions_executed' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function workflowRule(): BelongsTo
    {
        return $this->belongsTo(WorkflowRule::class);
    }
}
