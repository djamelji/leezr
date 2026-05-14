<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationPlan extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_compensation_plans';

    const PAY_FREQUENCIES = ['monthly', 'biweekly', 'weekly'];

    protected $fillable = [
        'company_id',
        'contract_id',
        'base_salary_cents',
        'currency',
        'pay_frequency',
        'overtime_rate_bps',
        'effective_from',
        'effective_until',
        'metadata',
    ];

    protected $casts = [
        'base_salary_cents' => 'integer',
        'overtime_rate_bps' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'contract_id');
    }

    // ── Scopes ──

    public function scopeActiveAt($query, $date = null)
    {
        $date ??= now();

        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }

    // ── Accessors ──

    public function getBaseSalaryAttribute(): float
    {
        return $this->base_salary_cents / 100;
    }

    public function getOvertimeRateAttribute(): float
    {
        return $this->overtime_rate_bps / 10000;
    }
}
