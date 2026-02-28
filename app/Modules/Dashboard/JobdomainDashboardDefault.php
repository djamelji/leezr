<?php

namespace App\Modules\Dashboard;

use App\Core\Jobdomains\Jobdomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobdomainDashboardDefault extends Model
{
    protected $fillable = ['jobdomain_id', 'layout_json', 'version'];

    protected function casts(): array
    {
        return [
            'layout_json' => 'array',
        ];
    }

    public function jobdomain(): BelongsTo
    {
        return $this->belongsTo(Jobdomain::class);
    }
}
