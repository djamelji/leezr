<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FULLTEXT indexes are MySQL only — skip on SQLite (tests)
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Full-text index on email_logs (body_text + subject)
        if (Schema::hasColumn('email_logs', 'body_text')) {
            DB::statement('ALTER TABLE email_logs ADD FULLTEXT INDEX ft_email_logs_body (body_text, subject)');
        }

        // Full-text index on email_threads (subject + participant_email + participant_name)
        DB::statement('ALTER TABLE email_threads ADD FULLTEXT INDEX ft_email_threads (subject, participant_email, participant_name)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE email_logs DROP INDEX ft_email_logs_body');
        DB::statement('ALTER TABLE email_threads DROP INDEX ft_email_threads');
    }
};
