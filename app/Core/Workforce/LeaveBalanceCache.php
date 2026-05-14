<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalanceCache extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_leave_balances';

    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_type_id',
        'period_year',
        'cached_accrued',
        'cached_reserved',
        'cached_consumed',
        'cached_adjusted',
        'cached_available',
        'last_computed_at',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'cached_accrued' => 'integer',
        'cached_reserved' => 'integer',
        'cached_consumed' => 'integer',
        'cached_adjusted' => 'integer',
        'cached_available' => 'integer',
        'last_computed_at' => 'datetime',
    ];

    // ── Relationships ──

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    // ── Scopes ──

    public function scopeForYear($query, int $year)
    {
        return $query->where('period_year', $year);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }
}
