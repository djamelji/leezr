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
}
