<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends Model
{
    protected $fillable = [
        'subject',
        'company_id',
        'participant_email',
        'participant_name',
        'status',
        'last_message_at',
        'unread_count',
        'message_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
        'message_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\Company::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'thread_id')->orderBy('created_at');
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'thread_id')->orderByDesc('created_at')->limit(1);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function scopeWithUnread($query)
    {
        return $query->where('unread_count', '>', 0);
    }

    public function markAllRead(): void
    {
        $this->messages()->where('is_read', false)->update(['is_read' => true]);
        $this->update(['unread_count' => 0]);
    }

    public function refreshCounts(): void
    {
        $this->update([
            'message_count' => $this->messages()->count(),
            'unread_count' => $this->messages()->where('is_read', false)->count(),
            'last_message_at' => $this->messages()->max('created_at'),
        ]);
    }
}
