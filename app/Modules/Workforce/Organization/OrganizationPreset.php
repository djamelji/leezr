<?php

namespace App\Modules\Workforce\Organization;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-636 (W-E) — preset organisationnel du catalogue Platform (global, non tenant-scoped).
 * `structure` = {departments:[{key,name,parent_key,sort_order}], job_roles:[{key,title,department_key,level}]}.
 */
class OrganizationPreset extends Model
{
    protected $fillable = [
        'key', 'family_key', 'label', 'jobdomain_key', 'market_key', 'org_profile_key',
        'version', 'status', 'structure', 'description', 'created_by', 'updated_by', 'published_at',
    ];

    protected $casts = [
        'structure' => 'array',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }

    public function departments(): array
    {
        return $this->structure['departments'] ?? [];
    }

    public function jobRoles(): array
    {
        return $this->structure['job_roles'] ?? [];
    }
}
