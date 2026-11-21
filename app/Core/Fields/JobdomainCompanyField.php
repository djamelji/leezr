<?php

namespace App\Core\Fields;

use App\Core\Jobdomains\Jobdomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-835 — a JobDomain's customisation of ONE company creation field of the global catalog.
 * Each attribute null = inherited (see CompanyCreationFields). Administered in Platform only.
 */
class JobdomainCompanyField extends Model
{
    protected $fillable = ['jobdomain_id', 'field_definition_id', 'visible', 'required', 'markets', 'default_value', 'updated_by_platform_user_id'];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'required' => 'boolean',
            'markets' => 'array',
        ];
    }

    public function jobdomain(): BelongsTo
    {
        return $this->belongsTo(Jobdomain::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'field_definition_id');
    }
}
