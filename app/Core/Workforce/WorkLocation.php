<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkLocation extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_work_locations';

    const TYPE_INTERNAL = 'internal';
    const TYPE_CLIENT_SITE = 'client_site';
    const TYPE_REMOTE = 'remote';
    const TYPE_MOBILE = 'mobile';

    const TYPES = [self::TYPE_INTERNAL, self::TYPE_CLIENT_SITE, self::TYPE_REMOTE, self::TYPE_MOBILE];

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'client_name',
        'client_reference',
        'address',
        'timezone',
        'enabled',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    // --- Scopes ---

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // --- Relationships ---

    public function scheduleTemplates()
    {
        return $this->hasMany(ScheduleTemplate::class, 'work_location_id');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'work_location_id');
    }
}
