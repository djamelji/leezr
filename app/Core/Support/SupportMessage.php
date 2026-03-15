<?php

namespace App\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportMessage extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'message_uuid',
        'ticket_id',
        'sender_type',
        'sender_id',
        'body',
        'attachments',
        'is_internal',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_internal' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportMessage $message) {
            $message->message_uuid ??= (string) Str::uuid();
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
