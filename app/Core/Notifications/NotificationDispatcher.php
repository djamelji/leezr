<?php

namespace App\Core\Notifications;

use App\Core\Models\Company;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationDispatcher
{
    /**
     * Central dispatch point for all notifications.
     * Creates in-app notifications + publishes SSE events + sends emails.
     */
    public static function send(
        string $topicKey,
        Collection|array $recipients,
        array $payload,
        ?Company $company = null,
        ?Notification $mailNotification = null,
    ): int {
        // 1. Load topic -> abort if explicitly deactivated
        $topic = NotificationTopic::find($topicKey);

        // If topic exists and is explicitly inactive, abort.
        // If topic not found (e.g. not yet seeded), default to active with email-only fallback.
        if ($topic && ! $topic->is_active) {
            return 0;
        }

        $recipients = $recipients instanceof Collection ? $recipients : collect($recipients);

        // Permission gate: filter recipients by category permission — ADR-382
        if ($company && $topic) {
            // Company scope: filter by company permission
            $requiredPerm = NotificationTopicRegistry::permissionForCategory($topic->category);

            if ($requiredPerm !== null) {
                $recipients = $recipients->filter(
                    fn ($r) => $r->hasCompanyPermission($company, $requiredPerm),
                );

                if ($recipients->isEmpty()) {
                    return 0;
                }
            }
        }
        elseif (! $company && $topic && $recipients->first() instanceof \App\Platform\Models\PlatformUser) {
            // Platform scope: filter platform admins by platform permission
            $requiredPerm = NotificationTopicRegistry::PLATFORM_CATEGORY_PERMISSIONS[$topic->category] ?? null;

            if ($requiredPerm !== null) {
                $recipients = $recipients->filter(
                    fn ($r) => $r->hasPermission($requiredPerm),
                );

                if ($recipients->isEmpty()) {
                    return 0;
                }
            }
        }

        $count = 0;

        foreach ($recipients as $recipient) {
            // 2. Load user preference (fallback to topic defaults, or email-only if topic not seeded)
            $channels = $topic
                ? NotificationPreference::channelsFor(
                    $recipient->id,
                    $topicKey,
                    $topic->default_channels ?? ['in_app', 'email'],
                )
                : ['email']; // Fallback when topic not seeded yet

            // 3. In-app channel: persist + SSE publish
            if (in_array('in_app', $channels)) {
                $uuid = (string) Str::uuid();
                $rendered = NotificationRenderer::render($topicKey, $payload, $recipient->locale ?? 'fr');

                $event = NotificationEvent::create([
                    'event_uuid' => $uuid,
                    'recipient_type' => $recipient->getMorphClass(),
                    'recipient_id' => $recipient->id,
                    'company_id' => $company?->id,
                    'topic_key' => $topicKey,
                    'title' => $rendered['title'],
                    'body' => $rendered['body'],
                    'icon' => $rendered['icon'],
                    'severity' => $rendered['severity'],
                    'link' => $rendered['link'],
                    'data' => $payload,
                ]);

                // Publish SSE envelope for instant delivery
                try {
                    $envelope = EventEnvelope::notification(
                        'notification.created',
                        $company?->id ?? 0,
                        [
                            'event_id' => $event->id,
                            'event_uuid' => $uuid,
                            'title' => $rendered['title'],
                            'body' => $rendered['body'],
                            'icon' => $rendered['icon'],
                            'severity' => $rendered['severity'],
                            'link' => $rendered['link'],
                            'topic_key' => $topicKey,
                            'created_at' => $event->created_at->toIso8601String(),
                        ],
                        $recipient->id,
                    );

                    app(RealtimePublisher::class)->publish($envelope);
                } catch (\Throwable $e) {
                    // SSE failure is non-fatal -- notification is persisted in DB
                    Log::warning('[notifications] SSE publish failed', [
                        'topic' => $topicKey,
                        'user_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $count++;
            }

            // 4. Email channel: use existing Laravel notification
            if (in_array('email', $channels) && $mailNotification) {
                try {
                    $recipient->notify($mailNotification);
                } catch (\Throwable $e) {
                    Log::warning('[notifications] Email send failed', [
                        'topic' => $topicKey,
                        'user_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $count;
    }
}
