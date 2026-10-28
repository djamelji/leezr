<?php

namespace App\Modules\Workforce\Organization;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** ADR-636 (W-E) — trace d'application d'un preset sur une Company (provenance + version + résumé). */
class OrganizationPresetApplication extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'preset_key', 'preset_version', 'applied_by', 'summary'];

    protected $casts = ['summary' => 'array', 'preset_version' => 'integer'];
}
