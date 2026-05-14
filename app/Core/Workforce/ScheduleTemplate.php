<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ScheduleTemplate extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_schedule_templates';

    protected $fillable = [
        'company_id',
        'name',
        'start_time',
        'end_time',
        'break_duration_minutes',
        'color',
        'work_location_id',
        'enabled',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'break_duration_minutes' => 'integer',
        'enabled' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    // --- Scopes ---

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    // --- Relationships ---

    public function workLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'schedule_template_id');
    }

    // --- Helpers ---

    public function toSnapshot(): array
    {
        return [
            'template_id' => $this->id,
            'template_name' => $this->name,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'break_duration_minutes' => $this->break_duration_minutes,
            'work_location_id' => $this->work_location_id,
            'color' => $this->color,
        ];
    }
}
