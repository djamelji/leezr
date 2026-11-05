<?php

namespace App\Core\Workforce;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Intention durable de notification de planning (Smart Planning Lot 3 complément, ADR-717).
 * Écrite dans la transaction du changement ; consommée par le flush (réutilise PlanningNotifier).
 */
class PlanningNotificationOutbox extends Model
{
    use BelongsToCompany;

    protected $table = 'workforce_planning_notification_outbox';

    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'topic_key', 'is_change', 'event_ref', 'groups', 'status', 'attempts', 'last_error', 'processed_at',
    ];

    protected $casts = [
        'is_change' => 'boolean',
        'groups' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
