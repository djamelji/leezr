<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make name nullable (legacy, no longer written)
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        Schema::table('platform_users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        // Backfill users
        DB::table('users')->orderBy('id')->each(function ($row) {
            $trimmed = trim($row->name ?? '');

            if ($trimmed === '') {
                $firstName = '';
                $lastName = '';
            } else {
                $parts = preg_split('/\s+/', $trimmed, 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';
            }

            DB::table('users')->where('id', $row->id)->update([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        });

        // Backfill platform_users
        DB::table('platform_users')->orderBy('id')->each(function ($row) {
            $trimmed = trim($row->name ?? '');

            if ($trimmed === '') {
                $firstName = '';
                $lastName = '';
            } else {
                $parts = preg_split('/\s+/', $trimmed, 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';
            }

            DB::table('platform_users')->where('id', $row->id)->update([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });

        Schema::table('platform_users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
