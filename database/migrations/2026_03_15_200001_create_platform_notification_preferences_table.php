<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_key');
            $table->json('channels');
            $table->timestamps();

            $table->unique(['platform_user_id', 'topic_key'], 'plat_notif_pref_user_topic_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_preferences');
    }
};
