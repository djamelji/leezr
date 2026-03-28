<?php

namespace App\Core\Automation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'automation_rule_id',
        'status',
        'actions_count',
        'duration_ms',
        'error',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class);
    }
}
