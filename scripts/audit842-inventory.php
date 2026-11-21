<?php
// Leezr 4.2 audit — READ-ONLY inventory of modules, permissions, jobdomains, plans, markets (marked clone only).
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit')) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$out = [];
foreach (App\Core\Modules\ModuleRegistry::definitions() as $key => $m) {
    $c = $m->capabilities;
    $out['modules'][$key] = [
        'name' => $m->name, 'scope' => $m->scope, 'type' => $m->type, 'surface' => $m->surface, 'visibility' => $m->visibility, 'hidden' => $m->hidden,
        'requires' => $m->requires, 'minPlan' => $m->minPlan, 'compatibleJobdomains' => $m->compatibleJobdomains, 'commercialStatus' => $m->commercialStatus,
        'navItems' => array_map(fn ($n) => array_intersect_key($n, array_flip(['key', 'title', 'to', 'permission', 'anyPermission', 'excludePermission', 'group', 'parent', 'children'])), $c->navItems ?? []),
        'routeNames' => $c->routeNames ?? [],
        'permissions' => array_column($m->permissions, 'key'),
        'bundles' => array_map(fn ($b) => ['key' => $b['key'], 'permissions' => $b['permissions'] ?? []], $m->bundles),
    ];
}
$out['jobdomains'] = DB::table('jobdomains')->get(['id', 'key', 'label', 'is_active', 'signup_available'])->all();
$out['plans'] = DB::table('plans')->get(['key', 'name', 'is_active', 'price_monthly', 'price_yearly', 'trial_days'])->all();
$out['markets'] = DB::table('markets')->get(['key', 'name', 'is_active'])->all();
$out['platform_roles'] = DB::table('platform_roles')->get(['id', 'key', 'name'])->map(fn ($r) => (array) $r + ['permissions' => DB::table('platform_role_permission')->join('platform_permissions', 'platform_permissions.id', '=', 'platform_role_permission.platform_permission_id')->where('platform_role_id', $r->id)->pluck('key')->all()])->all();
$out['platform_users'] = DB::table('platform_users')->get(['id', 'email'])->map(fn ($u) => ['id' => $u->id, 'email' => $u->email, 'roles' => DB::table('platform_role_user')->join('platform_roles', 'platform_roles.id', '=', 'platform_role_user.platform_role_id')->where('platform_user_id', $u->id)->pluck('key')->all()])->all();
$out['companies'] = DB::table('companies')->get(['id', 'name', 'jobdomain_key', 'plan_key', 'market_key', 'status'])->all();
file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "ok ".count($out['modules'])." modules\n";
