<?php
// Leezr 5.0 L6 (ADR-859) — browser recipe fixture, MARKED audit clone only, fictitious company 101 (E2E Recette).
// Creates (idempotent) an employee WITHOUT account whose file is COMPLETE : every item the completeness service
// requires is filled (profile values on the employee record, a file in the vault for each required document).
//   php scripts/audit842-files859-fixture.php <password-file>
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Models\Company;
use App\Core\Requirements\PersonFileCompleteness;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$company = Company::find(101);
if (! $company) { fwrite(STDERR, "company 101 missing\n"); exit(2); }
$e = Employee::withoutGlobalScopes()->firstOrCreate(['company_id' => 101, 'first_name' => 'L6', 'last_name' => 'Dossier Complet'],
    ['employee_number' => 'L6-COMPLETE', 'email' => 'l6.complet@leezr.invalid', 'status' => 'active', 'hire_date' => '2026-01-05']);
for ($pass = 0; $pass < 2; $pass++) {
    $file = PersonFileCompleteness::forEmployees($company, collect([$e->fresh()]))[$e->id];
    foreach ($file['missing'] as $m) {
        if ($m['kind'] === 'field') {
            $def = DB::table('field_definitions')->where('code', $m['code'])->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', 101))->first(['id', 'type', 'options']);
            $value = match ($def->type) { 'date' => '2026-01-05', 'select' => (json_decode($def->options ?? '[]', true)[0] ?? 'x'), 'number' => '1', 'boolean' => '1', default => 'Recette L6' };
            DB::table('field_values')->updateOrInsert(['model_type' => 'employee', 'model_id' => $e->id, 'field_definition_id' => $def->id], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        } else {
            $type = DB::table('document_types')->where('code', $m['code'])->value('id');
            DB::table('member_documents')->updateOrInsert(['company_id' => 101, 'document_type_id' => $type, 'document_subject_type' => 'employee', 'document_subject_id' => $e->id],
                ['file_path' => "recipe/l6/{$m['code']}.pdf", 'file_name' => "{$m['code']}.pdf", 'mime_type' => 'application/pdf', 'file_size_bytes' => 10, 'uploaded_by' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
$file = PersonFileCompleteness::forEmployees($company, collect([$e->fresh()]))[$e->id];
echo json_encode(['employee' => $e->id, 'complete' => $file['complete'], 'filled' => $file['filled'], 'total' => $file['total'], 'missing' => array_column($file['missing'], 'code')]), "\n";
