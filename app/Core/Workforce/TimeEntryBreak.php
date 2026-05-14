<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntryBreak extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_time_entry_breaks';

    const TYPE_LUNCH = 'lunch';
    const TYPE_REST = 'rest';
    const TYPE_PERSONAL = 'personal';

    const TYPES = [
        self::TYPE_LUNCH,
        self::TYPE_REST,
        self::TYPE_PERSONAL,
    ];

    protected $fillable = [
        'company_id',
        'time_entry_id',
        'start_at',
        'end_at',
        'type',
        'duration_minutes',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    // ── Relationships ──

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class, 'time_entry_id');
    }

    // ── Business Logic ──

    public function computeDuration(): void
    {
        if ($this->start_at && $this->end_at) {
            $this->duration_minutes = (int) $this->start_at->diffInMinutes($this->end_at);
        }
    }
}
