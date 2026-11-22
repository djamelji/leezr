<?php
// Leezr 5.0 L5 (ADR-858) — « old configuration » fixture, MARKED audit clone only, fictitious company 101 (E2E Recette).
// Reproduces what the retired « Outils → Champs » drawer could store, so that the audit / migration is proven on data :
//   hire_date   required_override=true  (already required by the JobDomain → company_redundant)
//   nationality required_override=true  (genuine company-wide obligation → company_global, preserved)
//   birth_city  disabled + required_override=true (→ company_dormant, ambiguous, preserved)
//   role Driver field_config hire_date required (→ role_redundant)
// `reset` as 2nd argument restores the neutral state (overrides false, birth_city enabled, Driver entry removed).
//   php scripts/audit842-fields858-fixture.php <password-file> [reset]
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$company = 101;
if (DB::table('companies')->where('id', $company)->value('name') === null) { fwrite(STDERR, "company $company missing\n"); exit(2); }
$reset = ($argv[2] ?? '') === 'reset';
$def = fn (string $code) => DB::table('field_definitions')->where('code', $code)->whereNull('company_id')->where('scope', 'company_user')->value('id');
$set = function (string $code, bool $enabled, bool $required) use ($company, $def) {
    DB::table('field_activations')->updateOrInsert(['company_id' => $company, 'field_definition_id' => $def($code)], ['enabled' => $enabled, 'required_override' => $required, 'updated_at' => now()]);
};
$set('hire_date', true, ! $reset);
$set('nationality', true, ! $reset);
$set('birth_city', $reset, ! $reset);
$driver = DB::table('company_roles')->where('company_id', $company)->where('key', 'driver')->first(['id', 'field_config']);
if ($driver) {
    $config = array_values(array_filter(json_decode($driver->field_config ?? '[]', true) ?: [], fn ($e) => ($e['code'] ?? null) !== 'hire_date'));
    if (! $reset) {
        $config[] = ['code' => 'hire_date', 'scope' => 'company_user', 'visible' => true, 'required' => true, 'order' => 99];
    }
    DB::table('company_roles')->where('id', $driver->id)->update(['field_config' => json_encode($config)]);
}
// the fictitious owner of company 101 gets the audit password (read from the file, never printed) for the browser recipe
$owner = DB::table('users')->where('email', 'e2e.8091.owner.433828@leezr.invalid')->value('id');
if ($owner && ! empty($argv[1]) && is_file($argv[1])) {
    DB::table('users')->where('id', $owner)->update(['password' => \Illuminate\Support\Facades\Hash::make(trim(file_get_contents($argv[1]))), 'password_set_at' => now()]);
}
echo json_encode(['company' => $company, 'state' => $reset ? 'reset' : 'old-configuration', 'owner' => (bool) $owner]), "\n";
