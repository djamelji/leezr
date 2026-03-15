<?php

namespace App\Core\Notifications;

use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformNotificationPreference extends Model
{
    protected $fillable = [
        'platform_user_id', 'topic_key', 'channels',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    public static function channelsFor(int $platformUserId, string $topicKey, array $defaults): array
    {
        $preference = static::query()
            ->where('platform_user_id', $platformUserId)
            ->where('topic_key', $topicKey)
            ->first();

        return $preference?->channels ?? $defaults;
    }
}
