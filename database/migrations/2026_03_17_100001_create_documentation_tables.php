<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_topics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->default('tabler-book');
            $table->string('audience')->default('company'); // platform, company, both
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audience', 'is_published']);
        });

        Schema::create('documentation_articles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('topic_id')->constrained('documentation_topics')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('audience')->default('company'); // platform, company, both
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['topic_id', 'slug']);
            $table->index(['audience', 'is_published']);

            // FULLTEXT index for MySQL only (SQLite doesn't support it)
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['title', 'excerpt', 'content']);
            }
        });

        Schema::create('documentation_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('documentation_articles')->cascadeOnDelete();
            $table->string('user_type'); // company_user, platform_admin
            $table->unsignedBigInteger('user_id');
            $table->boolean('helpful');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['article_id', 'user_type', 'user_id']);
            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_feedbacks');
        Schema::dropIfExists('documentation_articles');
        Schema::dropIfExists('documentation_topics');
    }
};
