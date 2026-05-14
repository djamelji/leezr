<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyPayrollRuleOverride extends Model
{
    use BelongsToCompany;

    protected $table = 'company_payroll_rule_overrides';

    const OVERRIDE_MODES = ['replace', 'supplement', 'minimum'];

    protected $fillable = [
        'company_id',
        'domain',
        'rule_type',
        'rule_key',
        'value',
        'effective_from',
        'effective_until',
        'override_mode',
        'approved_by',
        'justification',
        'source',
        'reference',
    ];

    protected $casts = [
        'value' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

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
