<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Catégorie de couverture opérationnelle (Smart Planning Lot A, ADR-710).
 * Qualifie à quel besoin un shift/une activité contribue (Route, Formation, Cuisine…). Catalogue par Company.
 */
class CoverageCategory extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_coverage_categories';

    protected $fillable = [
        'company_id', 'code', 'name', 'color', 'resource_type',
        'enabled', 'department_id', 'conditions', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'department_id' => 'integer',
        'conditions' => 'array',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
