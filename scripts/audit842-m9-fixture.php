<?php
// Leezr 4.2 M9 recipe fixture — MARKED audit clone only, fictitious company 106 only. Gives the fictitious employee
// M7-A a current full-time contract so the planning operation chain can be exercised (create → undo → redo → undo).
// Idempotent ; touches nothing else.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$c = 106;
if (! DB::table('companies')->where('id', $c)->where('name', 'like', 'Audit 842 Services%')->exists()) { fwrite(STDERR, "fictitious company 106 not found\n"); exit(2); }
$emp = DB::table('workforce_employees')->where('company_id', $c)->where('employee_number', 'M7-A')->value('id');
if (! $emp) { fwrite(STDERR, "fixture M7-A missing (run audit842-m7-fixture.php)\n"); exit(2); }
$model = 'horaire'; // the work model key used by existing contracts (argv[1] is the recipe password file, ADR-850)
DB::table('workforce_employment_contracts')->updateOrInsert(['company_id' => $c, 'employee_id' => $emp, 'is_current' => true],
    ['contract_type' => 'cdi', 'work_model_key' => $model, 'start_date' => '2026-01-01', 'weekly_hours' => 35, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
echo "M7-A has a current contract\n";
