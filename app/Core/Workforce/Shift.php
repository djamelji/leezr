<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_shifts';

    // --- Status constants ---

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PUBLISHED, self::STATUS_CANCELLED],
        self::STATUS_PUBLISHED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'schedule_template_id',
        'work_location_id',
        'template_snapshot',
        'date',
        'start_at',
        'end_at',
        'timezone',
        'status',
        'notes',
        'published_at',
        'cancelled_at',
        'cancelled_reason',
        'metadata',
    ];

    protected $casts = [
        'template_snapshot' => 'array',
        'date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'published_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    // --- State Machine ---

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): void
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition shift from '{$this->status}' to '{$newStatus}'."
            );
        }

        $this->status = $newStatus;
    }

    // --- Scopes ---

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_PUBLISHED]);
    }

    public function scopeNotCancelled($query)
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeOverlapping($query, $startAt, $endAt)
    {
        return $query->notCancelled()
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt);
    }

    // --- Relationships ---

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function scheduleTemplate()
    {
        return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id');
    }

    public function workLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    // --- Helpers ---

    public function plannedDurationMinutes(): int
    {
        return (int) $this->start_at->diffInMinutes($this->end_at);
    }

    public function plannedWorkMinutes(): int
    {
        $breakMinutes = $this->template_snapshot['break_duration_minutes'] ?? 0;

        return max(0, $this->plannedDurationMinutes() - $breakMinutes);
    }
}
