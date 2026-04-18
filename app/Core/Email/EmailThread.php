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
        'folder',
        'is_starred',
        'labels',
        'trashed_at',
        'last_message_at',
        'unread_count',
        'message_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
        'message_count' => 'integer',
        'is_starred' => 'boolean',
        'labels' => 'array',
        'trashed_at' => 'datetime',
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

    public function scopeFolder($query, string $folder)
    {
        return $query->where('folder', $folder);
    }

    public function scopeStarred($query)
    {
        return $query->where('is_starred', true);
    }

    public function scopeWithLabel($query, string $label)
    {
        return $query->whereJsonContains('labels', $label);
    }

    public function scopeSearch($query, string $search)
    {
        $escaped = addcslashes($search, '%_');
        $isMySQL = $query->getConnection()->getDriverName() === 'mysql';

        return $query->where(function ($q) use ($search, $escaped, $isMySQL) {
            if ($isMySQL) {
                $threadIds = EmailLog::whereRaw(
                    'MATCH(body_text, subject) AGAINST(? IN BOOLEAN MODE)',
                    [$search.'*']
                )->pluck('thread_id')->unique()->filter();

                $q->whereRaw('MATCH(subject, participant_email, participant_name) AGAINST(? IN BOOLEAN MODE)', [$search.'*'])
                    ->orWhereIn('id', $threadIds);
            }

            $q->orWhere('subject', 'LIKE', "%{$escaped}%")
                ->orWhere('participant_email', 'LIKE', "%{$escaped}%")
                ->orWhere('participant_name', 'LIKE', "%{$escaped}%");
        });
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
