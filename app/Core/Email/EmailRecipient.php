<?php

namespace App\Core\Email;

use Illuminate\Notifications\Notifiable;

class EmailRecipient
{
    use Notifiable;

    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {}

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
