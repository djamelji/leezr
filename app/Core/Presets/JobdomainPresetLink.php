<?php

namespace App\Core\Presets;

use Illuminate\Database\Eloquent\Model;

/** ADR-793 (L3) — a JobDomain RECOMMENDS a preset family (optionally for one market). Never applied by itself. */
class JobdomainPresetLink extends Model
{
    protected $fillable = ['jobdomain_key', 'market_key', 'kind', 'family_key', 'pinned_version', 'sort_order'];

    protected $casts = ['pinned_version' => 'integer'];
}
