<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-386: Add entity_key for collaborative notification resolution.
 *
 * entity_key links notifications to a specific business entity so that
 * when the entity's issue is resolved (e.g. document renewed), all
 * related unread notifications can be marked as read for ALL recipients.
 *
 * Format examples:
 *   - "company_document:{company_id}:{document_type_code}"
 *   - "member_document:{company_id}:{user_id}:{document_type_code}"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_events', function (Blueprint $table) {
            $table->string('entity_key')->nullable()->after('data');
            $table->index('entity_key', 'notif_events_entity_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_events', function (Blueprint $table) {
            $table->dropIndex('notif_events_entity_key_idx');
            $table->dropColumn('entity_key');
        });
    }
};
