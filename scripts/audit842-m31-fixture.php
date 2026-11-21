<?php
// Leezr 4.2 M3.1 recipe fixture — MARKED audit clone only. Fictitious company 105 : the delivery rights of its driver
// role are put back « awaiting the administrator's confirmation » with the EXISTING storage of ADR-808
// (company_module_setups.pending_access = {role_id: [keys]}). Nothing is granted, nothing historical is written.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
if (! DB::table('companies')->where('id', 105)->where('name', 'like', 'Recette 834 Transports Fictifs%')->exists()) { fwrite(STDERR, "fictitious company 105 not found\n"); exit(2); }
$driver = DB::table('company_roles')->where('company_id', 105)->where('key', 'driver')->value('id');
DB::table('company_module_setups')->where('company_id', 105)->where('module_key', 'logistics_shipments')
    ->update(['pending_access' => json_encode([(string) $driver => ['shipments.manage_status', 'shipments.view_own']]), 'updated_at' => now()]);
echo "company 105 : driver role $driver delivery rights awaiting confirmation\n";
