<?php

namespace App\Core\Notifications;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id', 'topic_key', 'channels',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve the active channels for a user+topic, falling back to defaults.
     */
    public static function channelsFor(int $userId, string $topicKey, array $defaults): array
    {
        $preference = static::query()
            ->where('user_id', $userId)
            ->where('topic_key', $topicKey)
            ->first();

        return $preference?->channels ?? $defaults;
    }
}
