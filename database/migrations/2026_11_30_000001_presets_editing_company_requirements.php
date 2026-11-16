<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-798 — additive only.
 *  - presets.revision : optimistic concurrency of draft editing (a stale editor never overwrites) ;
 *  - requirements.subject_scope : WHO a requirement is asked to — person (default, every existing row),
 *    company (the organisation itself : Kbis, insurance, SIRET…), legal_representative (a person-level
 *    document asked to the company's legal representative) ;
 *  - granular Platform preset permissions, granted ONLY to roles that already hold manage_jobdomains
 *    (same scope split in finer rights : no elevation).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'presets.view' => 'View versioned presets',
        'presets.edit' => 'Edit preset drafts',
        'presets.publish' => 'Publish or archive preset versions',
        'presets.preview_company' => 'Preview a preset on a company (read only)',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('presets', 'revision')) {
            Schema::table('presets', fn (Blueprint $t) => $t->unsignedInteger('revision')->default(0)->after('status'));
        }
        if (! Schema::hasColumn('requirements', 'subject_scope')) {
            Schema::table('requirements', function (Blueprint $t) {
                $t->string('subject_scope', 30)->default('person')->after('target_kind');
                $t->index(['company_id', 'subject_scope'], 'req_company_subject_idx');
            });
        }
        $holders = DB::table('platform_role_permission')
            ->join('platform_permissions', 'platform_permissions.id', '=', 'platform_role_permission.platform_permission_id')
            ->where('platform_permissions.key', 'manage_jobdomains')->pluck('platform_role_permission.platform_role_id');
        foreach (self::PERMISSIONS as $key => $label) {
            $id = DB::table('platform_permissions')->where('key', $key)->value('id')
                ?? DB::table('platform_permissions')->insertGetId(['key' => $key, 'label' => $label, 'module_key' => 'platform.jobdomains', 'is_admin' => false, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($holders as $roleId) {
                DB::table('platform_role_permission')->insertOrIgnore(['platform_role_id' => $roleId, 'platform_permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('platform_permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('platform_role_permission')->whereIn('platform_permission_id', $ids)->delete();
        DB::table('platform_permissions')->whereIn('id', $ids)->delete();
        Schema::table('requirements', function (Blueprint $t) {
            $t->dropIndex('req_company_subject_idx');
            $t->dropColumn('subject_scope');
        });
        Schema::table('presets', fn (Blueprint $t) => $t->dropColumn('revision'));
    }
};
