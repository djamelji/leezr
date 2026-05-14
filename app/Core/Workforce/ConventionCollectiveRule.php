<?php

namespace App\Core\Workforce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConventionCollectiveRule extends Model
{
    protected $table = 'convention_collective_rules';

    const RULE_TYPES = [
        'contribution',
        'salary_minimum',
        'overtime',
        'seniority_bonus',
        'leave',
        'notice_period',
        'classification',
        'benefit',
        'working_time',
    ];

    const OVERRIDE_MODES = ['replace', 'supplement', 'minimum'];

    protected $fillable = [
        'convention_collective_id',
        'domain',
        'rule_type',
        'rule_key',
        'value',
        'effective_from',
        'effective_until',
        'override_mode',
        'source',
        'reference',
    ];

    protected $casts = [
        'value' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    // ── Relationships ──

    public function conventionCollective(): BelongsTo
    {
        return $this->belongsTo(ConventionCollective::class, 'convention_collective_id');
    }

    // ── Scopes ──

    public function scopeDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopeRuleType($query, string $ruleType)
    {
        return $query->where('rule_type', $ruleType);
    }

    public function scopeActiveAt($query, $date = null)
    {
        $date ??= now();

        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }

    public function scopeForRule($query, string $ruleKey)
    {
        return $query->where('rule_key', $ruleKey);
    }
}
