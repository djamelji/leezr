<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_dsn_declarations', function (Blueprint $table) {
            $table->string('submission_reference')->nullable()->after('exported_at');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('submission_reference');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_dsn_declarations', function (Blueprint $table) {
            $table->dropColumn(['submission_reference', 'submitted_by', 'submitted_at']);
        });
    }
};
