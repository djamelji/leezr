<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-819 (Company 2.0) — targeted : the Logistique business profile keeps a MINIMAL set of person fields
 * activated at company creation ; the others stay in the catalogue as « recommended » (proposed, never
 * activated nor required). Existing companies are untouched : activations are only read at assignment.
 * No full registry sync (the DB presets diverged on purpose) — only the `recommended` flag is added.
 */
return new class extends Migration
{
    private const FIELDS = ['nationality', 'employee_status', 'social_security_number', 'iban', 'license_number', 'license_category',
        'license_expiry', 'adr_certified', 'vehicle_type', 'geographic_zone', 'work_schedule', 'work_mode'];

    private const DOCUMENTS = ['medical_certificate'];

    public function up(): void
    {
        $this->flag(true);
    }

    public function down(): void
    {
        $this->flag(false);
    }

    private function flag(bool $on): void
    {
        $row = DB::table('jobdomains')->where('key', 'logistique')->first(['id', 'default_fields', 'default_documents']);
        if (! $row) {
            return;
        }
        $apply = function (?string $json, array $codes) use ($on) {
            $list = json_decode($json ?? '[]', true) ?: [];
            foreach ($list as &$e) {
                if (in_array($e['code'] ?? null, $codes, true)) {
                    if ($on) {
                        $e['recommended'] = true;
                    } else {
                        unset($e['recommended']);
                    }
                }
            }

            return json_encode(array_values($list));
        };
        DB::table('jobdomains')->where('id', $row->id)->update([
            'default_fields' => $apply($row->default_fields, self::FIELDS),
            'default_documents' => $apply($row->default_documents, self::DOCUMENTS),
        ]);
    }
};
