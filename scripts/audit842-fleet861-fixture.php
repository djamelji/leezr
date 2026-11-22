<?php
// Leezr 5.0 L7.6 (ADR-861) — browser recipe fixture, MARKED audit clone only, clone company 1.
//   php scripts/audit842-fleet861-fixture.php <password-file> on|off
// on  : activity scope of module fields = function ; position « Livreur » declares driving
// off : back to the historical scope (everyone) ; the position declares nothing
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Fields\FunctionActivity;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$on = ($argv[2] ?? 'on') === 'on';
$job = JobRole::withoutGlobalScopes()->firstOrCreate(['company_id' => 1, 'title' => 'Livreur']);
$job->update(['metadata' => array_merge((array) ($job->metadata ?? []), ['activities' => $on ? ['driving'] : []])]);
FunctionActivity::setScope(1, $on ? FunctionActivity::SCOPE_FUNCTION : FunctionActivity::SCOPE_EVERYONE);
echo json_encode(['scope' => FunctionActivity::scopeOf(1), 'position' => $job->id]), "\n";
