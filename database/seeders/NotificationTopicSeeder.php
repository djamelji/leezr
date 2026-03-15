<?php

namespace Database\Seeders;

use App\Core\Notifications\NotificationTopicRegistry;
use Illuminate\Database\Seeder;

class NotificationTopicSeeder extends Seeder
{
    public function run(): void
    {
        NotificationTopicRegistry::boot();
        NotificationTopicRegistry::sync();
    }
}
