<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_topics', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('category'); // billing | members | modules | security | system
            $table->string('icon');
            $table->string('scope'); // company | platform | both
            $table->string('severity')->default('info'); // info | success | warning | error
            $table->json('default_channels'); // ['in_app', 'email']
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_topics');
    }
};
