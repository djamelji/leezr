<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveLedgerEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_leave_ledger_entries';

    // No updated_at — immutable table
    const UPDATED_AT = null;

    const TYPE_ACCRUAL = 'accrual';
    const TYPE_CONSUMPTION = 'consumption';
    const TYPE_RESERVATION = 'reservation';
    const TYPE_RELEASE = 'release';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_STORNO = 'storno';
    const TYPE_CARRY_OVER = 'carry_over';
    const TYPE_GRANT = 'grant';

    const ENTRY_TYPES = [
        self::TYPE_ACCRUAL,
        self::TYPE_CONSUMPTION,
        self::TYPE_RESERVATION,
        self::TYPE_RELEASE,
        self::TYPE_ADJUSTMENT,
        self::TYPE_STORNO,
        self::TYPE_CARRY_OVER,
        self::TYPE_GRANT,
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_type_id',
        'entry_type',
        'credit',
        'debit',
        'balance_after',
        'reference_type',
        'reference_id',
        'correlation_id',
        'period_key',
        'idempotency_key',
        'description',
        'actor_type',
        'actor_id',
        'recorded_at',
        'metadata',
    ];

    protected $casts = [
        'credit' => 'integer',
        'debit' => 'integer',
        'balance_after' => 'integer',
        'reference_id' => 'integer',
        'actor_id' => 'integer',
        'recorded_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ── IMMUTABILITY ENFORCEMENT ──

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('Leave ledger entries are immutable — updates are forbidden.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Leave ledger entries are immutable — deletes are forbidden.');
        });
    }

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

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForType($query, int $leaveTypeId)
    {
        return $query->where('leave_type_id', $leaveTypeId);
    }

    public function scopeForPeriod($query, string $periodKey)
    {
        return $query->where('period_key', $periodKey);
    }

    public function scopeOfEntryType($query, string $entryType)
    {
        return $query->where('entry_type', $entryType);
    }
}
