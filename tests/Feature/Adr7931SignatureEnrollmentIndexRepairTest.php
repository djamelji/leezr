<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** ADR-793.1 — réparation additive des index de workforce_signature_enrollments (nom > 64 caractères sur MySQL). */
class Adr7931SignatureEnrollmentIndexRepairTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'workforce_signature_enrollments';

    private function columnsSets(): array
    {
        return array_map(fn ($i) => $i['columns'], Schema::getIndexes(self::TABLE));
    }

    private function repair(): void
    {
        (require database_path('migrations/2026_11_23_000002_repair_signature_enrollment_indexes.php'))->up();
    }

    public function test_fresh_schema_uses_short_explicit_names_under_64_chars(): void
    {
        $names = array_column(Schema::getIndexes(self::TABLE), 'name');
        $this->assertContains('wf_sig_enroll_signer_created_idx', $names);
        $this->assertContains('wf_sig_enroll_company_idx', $names);
        foreach ($names as $n) {
            $this->assertLessThanOrEqual(64, strlen($n), $n);
        }
    }

    public function test_repair_recreates_missing_indexes_and_is_idempotent(): void
    {
        // État d'une base MySQL existante : table présente, les deux index absents.
        Schema::table(self::TABLE, function ($t) {
            $t->dropIndex('wf_sig_enroll_signer_created_idx');
            $t->dropIndex('wf_sig_enroll_company_idx');
        });
        $this->assertNotContains(['signature_signer_id', 'created_at'], $this->columnsSets());

        $this->repair();
        $this->assertContains(['signature_signer_id', 'created_at'], $this->columnsSets());
        $this->assertContains(['company_id'], $this->columnsSets());

        $count = count(Schema::getIndexes(self::TABLE));
        $this->repair(); // rejouable : aucun doublon
        $this->assertCount($count, Schema::getIndexes(self::TABLE));
    }
}
