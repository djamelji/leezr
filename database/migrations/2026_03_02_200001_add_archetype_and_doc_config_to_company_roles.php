<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-170 Phase 1: Add archetype, required_tags, doc_config to company_roles.
 *
 * - archetype: semantic role archetype (e.g. 'driver', 'dispatcher')
 * - required_tags: JSON array of tags that make fields/documents mandatory for this role
 * - doc_config: JSON array of document visibility/required overrides (same pattern as field_config)
 *
 * All nullable for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_roles', function (Blueprint $table) {
            $table->string('archetype')->nullable()->after('is_administrative');
            $table->json('required_tags')->nullable()->after('archetype');
            $table->json('doc_config')->nullable()->after('field_config');
        });
    }

    public function down(): void
    {
        Schema::table('company_roles', function (Blueprint $table) {
            $table->dropColumn(['archetype', 'required_tags', 'doc_config']);
        });
    }
};
