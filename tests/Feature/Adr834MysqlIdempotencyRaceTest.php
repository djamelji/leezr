<?php

namespace Tests\Feature;

use App\Console\Recipe\RecipeDatabaseGuard;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Platform\Companies\Creation\CreatePlatformCompanyUseCase;
use App\Modules\Platform\Companies\Creation\PlatformCompanyCreation;
use App\Platform\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-834 — REAL concurrency on MySQL : N processes submit the SAME idempotency key at the same instant.
 * Expected : exactly one company, one creation record, one administrator account ; every process gets that
 * company (the others are replays). sqlite cannot show this (database-level lock), hence MySQL.
 *
 * Runs only against a MARKED recipe clone (scripts/recipe/make-clone.sh) — never the local or a remote base :
 *   ADR834_MYSQL_RECIPE_DB=leezr_recipe834 php artisan test tests/Feature/Adr834MysqlIdempotencyRaceTest.php
 */
class Adr834MysqlIdempotencyRaceTest extends TestCase
{
    private const PROCESSES = 6;

    public function test_concurrent_submissions_with_the_same_key_create_exactly_one_company(): void
    {
        $db = (string) getenv('ADR834_MYSQL_RECIPE_DB');
        if ($db === '' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Set ADR834_MYSQL_RECIPE_DB to a marked MySQL recipe clone (and enable pcntl) to run the real race.');
        }
        $this->assertMatchesRegularExpression(RecipeDatabaseGuard::CLONE_PATTERN, $db);
        $this->assertNotContains($db, RecipeDatabaseGuard::PROTECTED_DATABASES);

        config(['database.connections.recipe834' => array_merge(config('database.connections.mysql'), ['database' => $db])]);
        DB::purge('recipe834');
        DB::setDefaultConnection('recipe834');
        $this->assertSame($db, DB::table(RecipeDatabaseGuard::MARKER_TABLE)->value('database_name'), 'not a marked recipe clone');

        $operator = PlatformUser::create(['first_name' => 'Race', 'last_name' => 'Op', 'email' => 'race-'.Str::lower(Str::random(6)).'@platform.test', 'password' => Str::random(32)]);
        $key = (string) Str::uuid();
        $name = 'Course 834 '.Str::random(5);
        $email = 'race.'.Str::lower(Str::random(6)).'@fictif.test';
        $payload = [
            'idempotency_key' => $key,
            'company' => ['name' => $name, 'market_key' => 'FR', 'dynamic_fields' => ['siret' => '12345678901234']],
            'jobdomain_key' => 'logistique',
            'module_keys' => [],
            'subscription' => ['plan_key' => 'pro', 'interval' => 'monthly'],
            'administrator' => ['first_name' => 'Race', 'last_name' => 'Admin', 'email' => $email],
        ];

        $dir = sys_get_temp_dir().'/adr834-race-'.Str::random(6);
        mkdir($dir);
        $startAt = microtime(true) + 1.5; // common start : every child sleeps until then
        DB::disconnect('recipe834');
        $pids = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge('recipe834');
                usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));
                try {
                    $r = app(CreatePlatformCompanyUseCase::class)->execute($payload, PlatformUser::find($operator->id));
                    file_put_contents("$dir/$i", json_encode(['company' => $r['company']->id, 'replayed' => $r['replayed']]));
                } catch (\Throwable $e) {
                    file_put_contents("$dir/$i", json_encode(['error' => class_basename($e).': '.Str::limit($e->getMessage(), 200)]));
                }
                posix_kill(getmypid(), SIGKILL); // leave without running the parent's shutdown handlers
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge('recipe834');

        $results = array_map(fn ($f) => json_decode(file_get_contents($f), true), glob("$dir/*"));
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);

        $this->assertCount(self::PROCESSES, $results, 'every process reported');
        $this->assertSame([], array_values(array_filter(array_column($results, 'error'))), 'no process failed');
        $ids = array_unique(array_column($results, 'company'));
        $this->assertCount(1, $ids, 'every process got the same company');
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['replayed'] === false)), 'exactly one real creation');
        $this->assertSame(1, Company::where('name', $name)->count());
        $this->assertSame(1, PlatformCompanyCreation::where('idempotency_key', $key)->count());
        $this->assertSame(1, User::where('email', $email)->count());
    }
}
