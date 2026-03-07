<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-228: Dead letter queue for failed webhook events.
 *
 * Events that fail processing are stored here for manual review
 * and automated replay via billing:webhook-replay.
 */
class BillingWebhookDeadLetter extends Model
{
    protected $fillable = [
        'provider_key', 'event_id', 'event_type',
        'payload', 'error_message', 'failed_at',
        'status', 'replayed_at', 'replay_attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_at' => 'datetime',
            'replayed_at' => 'datetime',
            'replay_attempts' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
