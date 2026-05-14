<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_leave_requests';

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_CONSUMED = 'consumed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_CONSUMED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PENDING, self::STATUS_CANCELLED],
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CONSUMED, self::STATUS_CANCELLED],
        self::STATUS_CONSUMED => [], // terminal
        self::STATUS_REJECTED => [], // terminal
        self::STATUS_CANCELLED => [], // terminal
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_type_id',
        'date_from',
        'date_to',
        'days_count_hundredths',
        'status',
        'reason',
        'reviewer_id',
        'review_note',
        'reviewed_at',
        'consumed_at',
        'ledger_correlation_id',
        'metadata',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'days_count_hundredths' => 'integer',
        'reviewed_at' => 'datetime',
        'consumed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ── State Machine ──

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition leave request from '{$this->status}' to '{$newStatus}'"
            );
        }

        $this->status = $newStatus;
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\User::class, 'reviewer_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class, 'reference_id')
            ->where('reference_type', 'leave_request');
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_CONSUMED]);
    }

    public function scopeOverlapping($query, $dateFrom, $dateTo)
    {
        return $query->where('date_from', '<=', $dateTo)
            ->where('date_to', '>=', $dateFrom);
    }
}
