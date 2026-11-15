<?php

namespace App\Core\Presets;

use Illuminate\Database\Eloquent\Model;

/** ADR-793 (L3) — versioned preset of the RBAC, REQUIREMENTS or PLANNING family (global catalogue). */
class Preset extends Model
{
    use VersionedPresetLifecycle;

    public const KINDS = ['rbac', 'requirements', 'planning'];

    protected $fillable = ['key', 'family_key', 'kind', 'label', 'description', 'jobdomain_key', 'market_key', 'version', 'status', 'content', 'requires_modules', 'created_by', 'updated_by', 'published_at'];

    protected $casts = ['content' => 'array', 'requires_modules' => 'array', 'version' => 'integer', 'published_at' => 'datetime'];

    public function contentAttributes(): array
    {
        return ['content', 'kind', 'label', 'description', 'jobdomain_key', 'market_key', 'requires_modules'];
    }
}
