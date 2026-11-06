<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Semaine type réutilisable (Refonte Smart Planning — M3, ADR-726). Catalogue par Company.
 *
 * Référence réutilisable, PAS un planning publié. Versionnée (schema_version). Les ScheduleTemplate de ses
 * lignes sont des références VIVANTES, résolues et figées par valeur (snapshot) à l'application (M1).
 */
class WeekTemplate extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_week_templates';

    public const STRATEGIES = ['nominative', 'needs', 'mixed'];
    public const SCOPES = ['company', 'department', 'location'];

    protected $fillable = [
        'company_id', 'name', 'description', 'strategy', 'scope',
        'department_id', 'work_location_id', 'enabled', 'schema_version', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'work_location_id' => 'integer',
        'enabled' => 'boolean',
        'schema_version' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function lines()
    {
        return $this->hasMany(WeekTemplateLine::class, 'week_template_id')->orderBy('day_index')->orderBy('sort_order');
    }
}
