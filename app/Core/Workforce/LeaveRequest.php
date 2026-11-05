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
        'start_time',
        'end_time',
        'days_count_hundredths',
        'hours_count_hundredths',
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
        'hours_count_hundredths' => 'integer',
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

    // ── Indisponibilité (Smart Planning Lot 3, ADR-717) ──

    /** La demande est-elle partielle (fenêtre horaire) plutôt que jour(s) entier(s) ? */
    public function isPartial(): bool
    {
        return $this->start_time !== null || $this->end_time !== null;
    }

    /**
     * Fenêtre d'INDISPONIBILITÉ du salarié le jour $date (heure murale locale), ou null s'il n'est pas
     * absent ce jour-là. Gère : jour entier (times NULL), multi-jours (bornes sur le 1er/dernier jour,
     * jours du milieu complets), absence horaire/demi-journée, shift de nuit (fenêtre bornée au jour civil).
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}|null
     */
    public function absentWindowOn(\Carbon\Carbon $date): ?array
    {
        $day = $date->toDateString();
        $from = $this->date_from->toDateString();
        $to = $this->date_to->toDateString();
        if ($day < $from || $day > $to) {
            return null;
        }

        $dayStart = \Illuminate\Support\Carbon::parse($day.' 00:00:00');
        $dayEnd = \Illuminate\Support\Carbon::parse($day.' 00:00:00')->addDay(); // 24:00 exclusif

        // Bornes partielles : heure de début appliquée au 1er jour, heure de fin au dernier jour.
        $start = ($day === $from && $this->start_time)
            ? \Illuminate\Support\Carbon::parse($day.' '.substr((string) $this->start_time, 0, 5))
            : $dayStart;
        $end = ($day === $to && $this->end_time)
            ? \Illuminate\Support\Carbon::parse($day.' '.substr((string) $this->end_time, 0, 5))
            : $dayEnd;

        if ($end->lte($start)) {
            return null;
        }

        return [$start, $end];
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
