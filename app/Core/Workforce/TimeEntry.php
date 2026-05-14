<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimeEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_time_entries';

    const STATUS_IDLE = 'idle';
    const STATUS_WORKING = 'working';
    const STATUS_ON_BREAK = 'on_break';
    const STATUS_COMPLETED = 'completed';

    const STATUSES = [
        self::STATUS_IDLE,
        self::STATUS_WORKING,
        self::STATUS_ON_BREAK,
        self::STATUS_COMPLETED,
    ];

    const TRANSITIONS = [
        self::STATUS_IDLE => [self::STATUS_WORKING],
        self::STATUS_WORKING => [self::STATUS_ON_BREAK, self::STATUS_COMPLETED],
        self::STATUS_ON_BREAK => [self::STATUS_WORKING],
        self::STATUS_COMPLETED => [],
    ];

    const SOURCES = ['manual', 'mobile', 'kiosk', 'import'];

    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'clock_in',
        'clock_out',
        'status',
        'total_worked_minutes',
        'total_break_minutes',
        'source',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'total_worked_minutes' => 'integer',
        'total_break_minutes' => 'integer',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(TimeEntryBreak::class, 'time_entry_id');
    }

    // ── State Machine ──

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? []);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException("Cannot transition TimeEntry from {$this->status} to {$newStatus}");
        }

        $this->status = $newStatus;
    }

    // ── Business Logic ──

    public function computeTotals(): void
    {
        if (! $this->clock_out) {
            return;
        }

        $totalMinutes = $this->clock_in->diffInMinutes($this->clock_out);
        $breakMinutes = $this->breaks()->whereNotNull('end_at')->sum('duration_minutes');

        $this->total_break_minutes = (int) $breakMinutes;
        $this->total_worked_minutes = $totalMinutes - $this->total_break_minutes;
    }

    // ── Scopes ──

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_WORKING, self::STATUS_ON_BREAK]);
    }
}
