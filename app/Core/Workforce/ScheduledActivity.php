<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Activité planifiée DANS un Shift (Smart Planning SP1b, ADR-705 v2).
 *
 * Plusieurs activités successives par shift (coupe→coloration→pause→balayage ; tournée+formation ;
 * interventions multi-sites…). PRÉVISIONNEL : ne génère JAMAIS d'heures travaillées — TimeEntry
 * reste la seule source du temps réel. La référence métier externe (subject) est optionnelle et dormante.
 */
class ScheduledActivity extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_scheduled_activities';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /**
     * Alias polymorphes autorisés pour la référence métier externe.
     * VIDE tant qu'aucun provider métier n'existe → toute référence externe est refusée (dormant).
     * Un futur module horizontal s'enregistrera ici (allow-list, pas de chargement de classe arbitraire).
     */
    public const ALLOWED_SUBJECT_TYPES = [];

    protected $fillable = [
        'company_id',
        'shift_id',
        'employee_id',
        'schedule_template_id',
        'work_location_id',
        'activity_type',
        'coverage_category_id',
        'label',
        'start_at',
        'end_at',
        'status',
        'priority',
        'source',
        'notes',
        'subject_type',
        'subject_id',
        'metadata',
    ];

    protected $casts = [
        'shift_id' => 'integer',
        'employee_id' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['duration_minutes'];

    // duration_minutes dérivée (non stockée).
    public function getDurationMinutesAttribute(): int
    {
        if (! $this->start_at || ! $this->end_at) {
            return 0;
        }

        return max(0, (int) $this->start_at->diffInMinutes($this->end_at));
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_PLANNED);
    }

    public function scopeForShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    // --- Relationships ---

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function workLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
