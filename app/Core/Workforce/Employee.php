<?php

namespace App\Core\Workforce;

use App\Core\Models\User;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_employees';

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ON_LEAVE = 'on_leave';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_TERMINATED = 'terminated';

    const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ON_LEAVE,
        self::STATUS_SUSPENDED,
        self::STATUS_TERMINATED,
    ];

    const TRANSITIONS = [
        self::STATUS_INACTIVE => [self::STATUS_ACTIVE],
        self::STATUS_ACTIVE => [self::STATUS_ON_LEAVE, self::STATUS_SUSPENDED, self::STATUS_TERMINATED],
        self::STATUS_ON_LEAVE => [self::STATUS_ACTIVE, self::STATUS_TERMINATED],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_TERMINATED],
        self::STATUS_TERMINATED => [],
    ];

    protected $fillable = [
        'company_id',
        'user_id',
        'employee_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'hire_date',
        'termination_date',
        'status',
        'metadata',
        'department_id',
        'job_role_id',
        'manager_id',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function jobRole(): BelongsTo
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'employee_id');
    }

    public function currentContract(): HasOne
    {
        return $this->hasOne(EmploymentContract::class, 'employee_id')
            ->where('is_current', true);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalanceCache::class, 'employee_id');
    }

    public function shifts()
    {
        return $this->hasMany(\App\Core\Workforce\Shift::class, 'employee_id');
    }

    public function timesheetPeriods()
    {
        return $this->hasMany(\App\Core\Workforce\TimesheetPeriod::class, 'employee_id');
    }

    public function payrollLines()
    {
        return $this->hasMany(\App\Core\Workforce\PayrollLine::class, 'employee_id');
    }

    public function ytdSnapshots()
    {
        return $this->hasMany(\App\Core\Workforce\PayrollYtdSnapshot::class, 'employee_id');
    }

    // ── State Machine ──

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? []);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition Employee from {$this->status} to {$newStatus}");
        }

        $this->status = $newStatus;
    }

    // ── Accessors ──

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeNotTerminated($query)
    {
        return $query->where('status', '!=', self::STATUS_TERMINATED);
    }

    public function scopeInDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    // ── Business Methods ──

    /**
     * Resolve effective hourly rate in cents using fallback hierarchy:
     * 1. Explicit hourly_rate_cents on current compensation
     * 2. Derived from monthly: base_salary / (weekly_hours × 52/12)
     * 3. Derived from daily: daily_rate / (weekly_hours / 5)
     * 4. Fallback to jobRole.default_hourly_rate_cents
     * 5. null → anomaly in payroll
     */
    public function effectiveHourlyRateCents(): ?int
    {
        $contract = $this->currentContract;
        if (! $contract) {
            return $this->jobRole?->default_hourly_rate_cents;
        }

        $compensation = $contract->currentCompensation;
        if (! $compensation) {
            return $this->jobRole?->default_hourly_rate_cents;
        }

        // (a) Explicit hourly rate
        if ($compensation->hourly_rate_cents !== null) {
            return $compensation->hourly_rate_cents;
        }

        $weeklyHours = (float) ($contract->weekly_hours ?? 35);

        if ($weeklyHours <= 0) {
            return $this->jobRole?->default_hourly_rate_cents;
        }

        $type = $compensation->compensation_type ?? 'monthly';

        // (b) Monthly → derive hourly
        if ($type === 'monthly' && $compensation->base_salary_cents > 0) {
            $monthlyHours = $weeklyHours * 52 / 12;

            return (int) round($compensation->base_salary_cents / $monthlyHours);
        }

        // (c) Daily → derive hourly
        if ($type === 'daily' && $compensation->daily_rate_cents !== null && $compensation->daily_rate_cents > 0) {
            $hoursPerDay = $weeklyHours / 5;

            return (int) round($compensation->daily_rate_cents / $hoursPerDay);
        }

        // (d) Fallback to job role
        return $this->jobRole?->default_hourly_rate_cents;
    }
}
