<?php

namespace App\Core\Notifications;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'event_uuid', 'recipient_type', 'recipient_id', 'company_id', 'topic_key',
        'title', 'body', 'icon', 'severity', 'link', 'data',
        'entity_key', 'delivered_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForRecipient(Builder $query, Model $recipient): Builder
    {
        return $query->where('recipient_type', $recipient->getMorphClass())
            ->where('recipient_id', $recipient->id);
    }

    public function scopeForEntity(Builder $query, string $entityKey): Builder
    {
        return $query->where('entity_key', $entityKey);
    }

    public function markRead(): void
    {
        $this->update(['read_at' => now()]);
    }
}
