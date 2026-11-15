<?php

namespace App\Core\Presets;

use Illuminate\Database\Eloquent\Model;

/** ADR-793 (L3) — one application of a preset version to a company (every family). Append-only. */
class PresetApplication extends Model
{
    protected $fillable = ['company_id', 'kind', 'family_key', 'preset_key', 'preset_version', 'selection', 'summary', 'applied_by_type', 'applied_by'];

    protected $casts = ['selection' => 'array', 'summary' => 'array', 'preset_version' => 'integer'];
}
