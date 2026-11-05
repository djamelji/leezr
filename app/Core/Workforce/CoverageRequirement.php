<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Besoin de PERSONNEL (Smart Planning SP1a, ADR-705).
 *
 * « Combien de collaborateurs présents » — distinct de la demande d'activité (workload, SP2).
 * Récurrent (day_of_week) OU ponctuel (date). Fenêtre horaire optionnelle (null = journée entière).
 */
class CoverageRequirement extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_coverage_requirements';

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    protected $fillable = [
        'company_id',
        'label',
        'date',
        'day_of_week',
        'start_time',
        'end_time',
        'department_id',
        'work_location_id',
        'activity_type',
        'coverage_category_id',
        'headcount',
        'priority',
        'source',
        'enabled',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'day_of_week' => 'integer',
        'department_id' => 'integer',
        'work_location_id' => 'integer',
        'headcount' => 'integer',
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function workLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /** Un besoin est-il applicable à une date donnée ? (ponctuel = date exacte ; récurrent = même jour de semaine). */
    public function appliesToDate(\Carbon\CarbonInterface $date): bool
    {
        if ($this->date !== null) {
            return $this->date->isSameDay($date);
        }

        if ($this->day_of_week !== null) {
            return (int) $date->dayOfWeek === (int) $this->day_of_week;
        }

        return false;
    }
}
