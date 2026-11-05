<?php

namespace App\Notifications\Workforce;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email de publication / modification du planning (Smart Planning Lot 2, ADR-715).
 * Canal mail uniquement — l'in-app est géré par NotificationDispatcher (NotificationEvent). Mis en queue.
 */
class PlanningPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $periodLabel,
        public int $shiftCount,
        public string $url,
        public bool $isChange = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->isChange
            ? "Votre planning a été modifié ({$this->periodLabel})"
            : "Votre planning est publié ({$this->periodLabel})";

        return (new MailMessage())
            ->subject($subject)
            ->greeting('Bonjour,')
            ->line($this->isChange
                ? "Votre planning de la période {$this->periodLabel} a été modifié."
                : "Votre planning de la période {$this->periodLabel} est désormais disponible.")
            ->line("{$this->shiftCount} créneau(x) planifié(s).")
            ->action('Consulter mon planning', $this->url)
            ->line('Connectez-vous à votre espace pour voir le détail.');
    }
}
