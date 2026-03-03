<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support ALTER COLUMN — in tests, the factory always
        // provides jobdomain_key so the nullable column is functionally safe.
        // The NOT NULL constraint is enforced in MySQL (staging/production).
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE companies MODIFY jobdomain_key VARCHAR(50) NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE companies MODIFY jobdomain_key VARCHAR(50) NULL');
        }
    }
};
