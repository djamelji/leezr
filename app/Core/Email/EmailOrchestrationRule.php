<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailOrchestrationRule extends Model
{
    protected $fillable = [
        'template_key', 'trigger_event', 'timing', 'frequency', 'delay_value', 'delay_unit',
        'conditions', 'max_sends', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_active' => 'boolean',
        'max_sends' => 'integer',
        'delay_value' => 'integer',
        'sort_order' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_key', 'key');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->where('trigger_event', $event);
    }

    /**
     * Evaluate conditions against context data.
     * Simple key-value matching with optional operators.
     */
    public function evaluateConditions(array $context): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $key => $expected) {
            $actual = $context[$key] ?? null;

            if (is_array($expected)) {
                // Operator-based: {"days_remaining": {"<=": 3}}
                foreach ($expected as $op => $val) {
                    $pass = match ($op) {
                        '<=' => $actual <= $val,
                        '>=' => $actual >= $val,
                        '<' => $actual < $val,
                        '>' => $actual > $val,
                        '=' => $actual == $val,
                        '!=' => $actual != $val,
                        default => true,
                    };
                    if (!$pass) return false;
                }
            } else {
                if ($actual != $expected) return false;
            }
        }

        return true;
    }

    public function delayDescription(): string
    {
        if ($this->timing === 'immediate') {
            return 'Immediate';
        }

        return "{$this->delay_value} {$this->delay_unit}";
    }
}
