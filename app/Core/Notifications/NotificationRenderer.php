<?php

namespace App\Core\Notifications;

class NotificationRenderer
{
    /**
     * Render notification title/body/icon/link from topic + payload.
     * Uses i18n keys: notifications.{topic_key}.title and .body
     * Falls back to topic label if no i18n key found.
     */
    public static function render(string $topicKey, array $payload, string $locale = 'fr'): array
    {
        $topic = NotificationTopic::find($topicKey);

        // i18n keys: notifications.billing.payment_failed.title
        $i18nPrefix = 'notifications.' . str_replace('.', '_', $topicKey);

        $title = self::translate($i18nPrefix . '.title', $payload, $locale)
            ?? ($topic?->label ?? $topicKey);

        $body = self::translate($i18nPrefix . '.body', $payload, $locale);

        return [
            'title' => $title,
            'body' => $body,
            'icon' => $topic?->icon ?? 'tabler-bell',
            'severity' => $topic?->severity ?? 'info',
            'link' => $payload['link'] ?? null,
        ];
    }

    private static function translate(string $key, array $payload, string $locale): ?string
    {
        $translated = __($key, $payload, $locale);

        // __() returns the key itself if no translation found
        return $translated === $key ? null : $translated;
    }
}
