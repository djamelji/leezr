<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'in_reply_to',
        'external_id',
        'company_id',
        'recipient_email',
        'recipient_name',
        'cc',
        'bcc',
        'from_email',
        'reply_to',
        'subject',
        'body_html',
        'body_text',
        'template_key',
        'notification_class',
        'status',
        'direction',
        'thread_id',
        'is_read',
        'is_draft',
        'error_message',
        'headers',
        'metadata',
        'sent_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'is_read' => 'boolean',
        'is_draft' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\Company::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeDirection($query, string $direction)
    {
        return $query->where('direction', $direction);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', 'received');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'sent');
    }

    public function markSent(?string $externalId = null): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'external_id' => $externalId ?? $this->external_id,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
