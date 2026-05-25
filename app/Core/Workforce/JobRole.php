<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobRole extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_job_roles';

    protected $fillable = [
        'company_id',
        'department_id',
        'title',
        'level',
        'description',
        'default_hourly_rate_cents',
        'metadata',
    ];

    protected $casts = [
        'default_hourly_rate_cents' => 'integer',
        'metadata' => 'array',
    ];

    // ── Relationships ──

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'job_role_id');
    }
}
