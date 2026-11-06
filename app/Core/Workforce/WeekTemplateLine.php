<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Ligne d'une semaine type (Refonte Smart Planning — M3, ADR-726).
 *
 * day_index 0..6 RELATIF au premier jour de semaine de la Company (jamais lundi en dur).
 * kind=nominative → employee_id désigné ; kind=needs → headcount à pourvoir (affectation à la confirmation).
 * Toutes les références (schedule_template_id, employee_id, lieux, department, catégorie) sont souples.
 */
class WeekTemplateLine extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_week_template_lines';

    public const KINDS = ['nominative', 'needs'];

    protected $fillable = [
        'company_id', 'week_template_id', 'day_index', 'schedule_template_id', 'kind',
        'employee_id', 'headcount', 'work_location_id', 'department_id', 'coverage_category_id', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'day_index' => 'integer',
        'schedule_template_id' => 'integer',
        'employee_id' => 'integer',
        'headcount' => 'integer',
        'work_location_id' => 'integer',
        'department_id' => 'integer',
        'coverage_category_id' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function weekTemplate()
    {
        return $this->belongsTo(WeekTemplate::class, 'week_template_id');
    }
}
