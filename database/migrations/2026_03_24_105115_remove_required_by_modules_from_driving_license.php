<?php

use App\Core\Documents\DocumentType;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-389 fix: Remove required_by_modules from driving_license.
 *
 * The driving license should NOT be mandatory for ALL members when logistics_fleet
 * is active. Obligation is role-specific via tags: ['driving'] — only roles with
 * required_tags ['driving'] (i.e. Driver archetype) enforce mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        $type = DocumentType::where('code', 'driving_license')->first();

        if (! $type) {
            return;
        }

        $rules = $type->validation_rules ?? [];

        if (isset($rules['required_by_modules'])) {
            unset($rules['required_by_modules']);
            $type->validation_rules = $rules;
            $type->save();
        }
    }

    public function down(): void
    {
        $type = DocumentType::where('code', 'driving_license')->first();

        if (! $type) {
            return;
        }

        $rules = $type->validation_rules ?? [];
        $rules['required_by_modules'] = ['logistics_fleet'];
        $type->validation_rules = $rules;
        $type->save();
    }
};
