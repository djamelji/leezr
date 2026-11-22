<?php
// Leezr 5.0 L4 (ADR-856) widget recipe fixture — MARKED audit clone only. The driver of company 1 (bob@leezr.test,
// field_worker) gets a stored layout that deliberately contains widgets outside his catalog (billing.plan_badge,
// workforce.headcount) next to one he may see (deliveries.my_today) : the dashboard must never serve the former.
//   php scripts/audit842-widgets856-fixture.php <password-file>
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$bob = DB::table('users')->where('email', 'bob@leezr.test')->value('id');
$company = DB::table('memberships')->where('user_id', $bob)->value('company_id');
if (! $bob || ! $company) { fwrite(STDERR, "driver not found\n"); exit(2); }
$t = fn ($k, $x, $y, $w, $h) => ['key' => $k, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'scope' => 'company', 'config' => []];
DB::table('company_dashboard_layouts')->updateOrInsert(['company_id' => $company, 'user_id' => $bob], [
    'company_role_id' => null,
    'layout_json' => json_encode([$t('billing.plan_badge', 0, 0, 12, 2), $t('workforce.headcount', 0, 2, 3, 2), $t('deliveries.my_today', 3, 2, 3, 2)]),
    'created_at' => now(), 'updated_at' => now(),
]);
// WIDGETS856_STALE=1 : the owner of the same company gets a layout saved BEFORE the ADR-856 bounds (KPI 6×5,
// breakdown KPI 6×4) — the dashboard must display it within bounds and save it without a 422.
$owner = null;
if (getenv('WIDGETS856_STALE') === '1') {
    $owner = DB::table('users')->where('email', 'owner@leezr.test')->value('id');
    DB::table('company_dashboard_layouts')->updateOrInsert(['company_id' => $company, 'user_id' => $owner], [
        'company_role_id' => null,
        'layout_json' => json_encode([$t('workforce.headcount', 0, 0, 6, 5), $t('planning.shifts', 6, 0, 6, 4)]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
echo json_encode(['driver' => $bob, 'company' => $company, 'stale_owner' => $owner]), "\n";
