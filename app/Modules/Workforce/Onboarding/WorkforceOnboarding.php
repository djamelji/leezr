<?php

namespace App\Modules\Workforce\Onboarding;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** ADR-637 (W-F) — état persisté du wizard d'onboarding RH (1 par Company). */
class WorkforceOnboarding extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'version', 'status', 'current_step',
        'completed_steps', 'deferred_steps', 'applied_summary', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'completed_steps' => 'array',
        'deferred_steps' => 'array',
        'applied_summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
}
