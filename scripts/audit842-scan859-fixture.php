<?php
// Leezr 5.0 L7 closure (ADR-862) — browser recipe probe, MARKED audit clone only, read only.
//   php scripts/audit842-scan859-fixture.php <password-file> <since-unix-ts>
// Prints the antivirus engine recorded on the onboarding uploads created since <since> : the hiring recipe asserts it
// is `simulated`, i.e. that it runs against the recipe server (scripts/audit842-serve.sh), never against a real scanner
// or a server where uploads silently stay in quarantine.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$since = date('Y-m-d H:i:s', (int) ($argv[2] ?? time()) - 5);
$rows = DB::table('onboarding_uploads')->where('created_at', '>=', $since)->get(['status', 'scan_engine']);
echo json_encode(['uploads' => $rows->count(), 'engines' => $rows->pluck('scan_engine')->unique()->values(), 'statuses' => $rows->pluck('status')->unique()->values()]), "\n";
