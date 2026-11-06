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
        'code',
        'start_time',
        'end_time',
        'break_duration_minutes',
        'color',
        'icon',
        'activity_type',
        'coverage_category_id',
        'work_location_id',
        'department_id',
        'enabled',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'break_duration_minutes' => 'integer',
        'department_id' => 'integer',
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

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'schedule_template_id');
    }

    // --- Helpers ---

    public function toSnapshot(): array
    {
        $snapshot = [
            'template_id' => $this->id,
            'template_name' => $this->name,
            'code' => $this->code,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'break_duration_minutes' => $this->break_duration_minutes,
            'work_location_id' => $this->work_location_id,
            'department_id' => $this->department_id,
            'activity_type' => $this->activity_type,
            'coverage_category_id' => $this->coverage_category_id,
            'icon' => $this->icon,
            'color' => $this->color,
        ];

        // M1 (ADR-724) — composition enrichie (activités + pauses positionnées), figée par valeur.
        // Compatibilité ascendante : sans composition, le snapshot reste strictement l'ancien format.
        $composition = is_array($this->metadata ?? null) ? ($this->metadata['composition'] ?? null) : null;
        if (is_array($composition) && (int) ($composition['schema_version'] ?? 0) === 1) {
            $snapshot['schema_version'] = 1;
            $snapshot['activities'] = array_values($composition['activities'] ?? []);

            $breaks = $composition['breaks'] ?? [];
            if ($breaks) {
                // Les pauses relatives (offset depuis le début) sont converties en fenêtres HH:mm, format
                // DÉJÀ attendu par ScheduledActivityValidator (une seule représentation, pas de doublon).
                $startMin = self::timeToMinutes((string) $this->start_time);
                $windows = [];
                foreach ($breaks as $b) {
                    $s = $startMin + (int) $b['offset_minutes'];
                    $e = $s + (int) $b['duration_minutes'];
                    $windows[] = ['start' => self::minutesToHHMM($s), 'end' => self::minutesToHHMM($e)];
                }
                $snapshot['break_windows'] = $windows;
            }
        }

        return $snapshot;
    }

    private static function timeToMinutes(string $t): int
    {
        $p = explode(':', $t);

        return ((int) ($p[0] ?? 0)) * 60 + (int) ($p[1] ?? 0);
    }

    private static function minutesToHHMM(int $m): string
    {
        $m = (($m % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
}
