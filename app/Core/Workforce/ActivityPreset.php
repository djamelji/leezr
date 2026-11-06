<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Tâche type réutilisable (Refonte Smart Planning — M2, ADR-725). Catalogue par Company.
 *
 * Référence réutilisable et modifiable. Ses valeurs sont COPIÉES PAR VALEUR dans la composition d'un
 * ScheduleTemplate (ScheduleTemplate.metadata['composition'].activities[].preset_id = provenance facultative).
 * Modifier/désactiver/supprimer un preset n'altère JAMAIS une composition ni une ScheduledActivity existante.
 * N'est NI une catégorie de couverture, NI une ScheduledActivity.
 */
class ActivityPreset extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_activity_presets';

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'activity_type',
        'coverage_category_id',
        'work_location_id',
        'default_duration_minutes',
        'priority',
        'non_exclusive',
        'color',
        'icon',
        'description',
        'enabled',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'coverage_category_id' => 'integer',
        'work_location_id' => 'integer',
        'default_duration_minutes' => 'integer',
        'non_exclusive' => 'boolean',
        'enabled' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
