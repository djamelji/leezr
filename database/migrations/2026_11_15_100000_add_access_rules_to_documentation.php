<?php

use App\Core\Documentation\HelpCenterAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-787 — additive : optional access rules on help-center topics and articles
 * (`required_permissions` = any of, `required_modules` = all of). Applies the default rules to the
 * shipped content and adds the employee topic, without overwriting nor deleting anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['documentation_topics', 'documentation_articles'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'required_permissions')) {
                    $t->json('required_permissions')->nullable();
                }
                if (! Schema::hasColumn($table, 'required_modules')) {
                    $t->json('required_modules')->nullable();
                }
            });
        }

        HelpCenterAccess::applyDefaults();
    }

    public function down(): void
    {
        foreach (['documentation_topics', 'documentation_articles'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['required_permissions', 'required_modules']));
        }
    }
};
