<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            // Add company_id (nullable = platform-owned, non-null = company-owned)
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Drop global unique on code — replaced by composite
            $table->dropUnique(['code']);

            // Composite unique: code unique per company (NULL = platform scope)
            // Note: NULL != NULL in SQLite & MySQL, so platform uniqueness
            // is enforced at application level (FieldDefinitionCatalog::sync + controller validation)
            $table->unique(['company_id', 'code']);

            // Performance index for tenant filtering
            $table->index(['company_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'scope']);
            $table->dropUnique(['company_id', 'code']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->unique('code');
        });
    }
};
