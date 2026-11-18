<?php

namespace Tests\Feature;

use App\Console\Recipe\RecipeCommand;
use App\Console\Recipe\RecipeDatabaseGuard;
use App\Core\Models\Company;
use App\Core\Workforce\PayrollRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PDO;
use RuntimeException;
use Tests\Support\AllowingRecipeGuard;
use Tests\TestCase;

/**
 * ADR-827 — recipe (E2E fixture) commands refuse every database that does not PROVE it is a marked isolated clone,
 * at the real point of execution (child processes included), and never leave a partial write.
 */
class Adr827RecipeGuardTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $template = null;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private static function id(string $actual, ?array $markers, ?string $configured = null): array
    {
        return ['connection' => 'mysql', 'driver' => 'mysql', 'configured' => $configured ?? $actual, 'actual' => $actual, 'markers' => $markers];
    }

    public function test_decision_matrix(): void
    {
        $ok = self::id('leezr_audit827', ['leezr_audit827']);
        $this->assertSame([], RecipeDatabaseGuard::decide($ok, 'local'), 'marked clone allowed');
        $this->assertSame([], RecipeDatabaseGuard::decide(self::id('leezr_recipe1', ['leezr_recipe1']), 'testing'));

        $refused = [
            'DEV' => [self::id('leezr', null), 'local'],
            'DEV even if marked' => [self::id('leezr', ['leezr']), 'local'],
            'production db' => [self::id('c1leezr', ['c1leezr']), 'local'],
            'VPS DEV db' => [self::id('c1leezrdev', null), 'local'],
            'production env' => [$ok, 'production'],
            'unknown name' => [self::id('shop_db', ['shop_db']), 'local'],
            'empty configuration' => [self::id('', null), 'local'],
            'in-memory database' => [['driver' => 'sqlite', 'configured' => ':memory:', 'actual' => null, 'markers' => null], 'testing'],
            'misleading : configured clone, connected DEV' => [self::id('leezr', null, 'leezr_audit827'), 'local'],
            'clone without marker' => [self::id('leezr_audit827', null), 'local'],
            'clone dump restored elsewhere' => [self::id('leezr_audit900', ['leezr_audit827']), 'local'],
            'two markers' => [self::id('leezr_audit827', ['leezr_audit827', 'leezr']), 'local'],
            'connection error' => [['error' => 'SQLSTATE[HY000] [2002]'], 'local'],
            'APP_ENV testing is not a proof' => [self::id('leezr', null), 'testing'],
        ];
        foreach ($refused as $label => [$identity, $env]) {
            $this->assertNotSame([], RecipeDatabaseGuard::decide($identity, $env), $label);
        }
    }

    public function test_every_e2e_command_is_a_guarded_recipe_command(): void
    {
        $e2e = collect(app(Kernel::class)->all())->filter(fn ($c, $name) => str_starts_with($name, 'e2e:'));
        $this->assertGreaterThanOrEqual(12, $e2e->count());
        foreach ($e2e as $name => $command) {
            $this->assertInstanceOf(RecipeCommand::class, $command, "$name must extend RecipeCommand");
        }
    }

    public function test_the_real_guard_refuses_the_test_database_and_writes_nothing(): void
    {
        $company = Company::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $run = PayrollRun::withoutGlobalScopes()->create(['company_id' => $company->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'status' => PayrollRun::STATUS_DRAFT, 'currency' => 'EUR']);

        $code = Artisan::call('e2e:reset-payroll', ['--company' => $company->id, '--from' => '2026-04-01', '--to' => '2026-04-30']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('RECIPE GUARD', Artisan::output());
        $this->assertNotNull(PayrollRun::withoutGlobalScopes()->find($run->id), 'nothing deleted');
    }

    public function test_a_failing_recipe_command_leaves_no_partial_write(): void
    {
        $this->app->instance(RecipeDatabaseGuard::class, new AllowingRecipeGuard);
        $company = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $runs = collect(['2026-04-01', '2026-05-01'])->map(fn ($d) => PayrollRun::withoutGlobalScopes()->create(['company_id' => $company->id, 'period_start' => $d,
            'period_end' => date('Y-m-t', strtotime($d)), 'status' => PayrollRun::STATUS_DRAFT, 'currency' => 'EUR']));

        $command = new class extends RecipeCommand
        {
            protected $signature = 'e2e:adr827-interrupted {--company=}';

            public function handle(): int
            {
                PayrollRun::withoutGlobalScopes()->where('company_id', $this->option('company'))->orderBy('id')->first()->delete();

                throw new RuntimeException('interrupted after the first delete');
            }
        };
        app(Kernel::class)->registerCommand($command);

        try {
            Artisan::call('e2e:adr827-interrupted', ['--company' => $company->id]);
            $this->fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('interrupted after the first delete', $e->getMessage());
        }
        $this->assertSame(2, PayrollRun::withoutGlobalScopes()->where('company_id', $company->id)->count(), 'the first delete was rolled back');
        $this->assertSame(0, DB::transactionLevel() - 1, 'no transaction left open (RefreshDatabase holds one)');
    }

    /** A migrated sqlite database file named $name, with one payroll run for company 1, optionally marked. */
    private function database(string $name, ?string $marker): string
    {
        if (self::$template === null) {
            self::$template = sys_get_temp_dir().'/adr827-template-'.getmypid().'.sqlite';
            touch(self::$template);
            $r = Process::env($this->childEnv(self::$template))->timeout(180)->run(['php', 'artisan', 'migrate', '--force']);
            $this->assertTrue($r->successful(), $r->errorOutput());
            register_shutdown_function(fn () => @unlink(self::$template));
        }
        $file = sys_get_temp_dir()."/{$name}.sqlite";
        copy(self::$template, $file);
        $this->files[] = $file;
        $pdo = new PDO("sqlite:$file");
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec("INSERT INTO companies (id, name, slug, jobdomain_key, market_key, created_at, updated_at) VALUES (1, 'C', 'c-adr827', 'logistique', 'FR', '2026-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO workforce_payroll_runs (company_id, period_start, period_end, status, currency, created_at, updated_at) VALUES (1, '2026-04-01', '2026-04-30', 'exported', 'EUR', '2026-01-01', '2026-01-01')");
        if ($marker !== null) {
            $pdo->exec('CREATE TABLE '.RecipeDatabaseGuard::MARKER_TABLE.' (database_name varchar(64) primary key, source_database varchar(64), source_dump_sha256 char(64), created_at datetime)');
            $pdo->exec('INSERT INTO '.RecipeDatabaseGuard::MARKER_TABLE." VALUES ('$marker', 'leezr', '', '2026-11-18')");
        }

        return $file;
    }

    private function childEnv(string $file, string $env = 'local'): array
    {
        return ['APP_ENV' => $env, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'DB_URL' => '', 'DB_FOREIGN_KEYS' => 'false'];
    }

    private function runs(string $file): int
    {
        return (int) (new PDO("sqlite:$file"))->query('SELECT COUNT(*) FROM workforce_payroll_runs')->fetchColumn();
    }

    public function test_child_processes_cannot_bypass_the_guard(): void
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $cases = [
            'DEV-like name' => [$this->database("leezr_dev_like_{$suffix}", null), 'local'],
            'unknown name, marked' => [$this->database("shop_{$suffix}", "shop_{$suffix}"), 'local'],
            'clone name, no marker' => [$this->database("leezr_recipe_nomark_{$suffix}", null), 'local'],
            'clone name, foreign marker' => [$this->database("leezr_recipe_copy_{$suffix}", 'leezr_recipe_original'), 'local'],
            'clone name, APP_ENV testing, no marker' => [$this->database("leezr_recipe_t_{$suffix}", null), 'testing'],
            'marked clone, APP_ENV production' => [$this->database("leezr_recipe_p_{$suffix}", "leezr_recipe_p_{$suffix}"), 'production'],
        ];
        foreach ($cases as $label => [$file, $env]) {
            $r = Process::env($this->childEnv($file, $env))->timeout(60)
                ->run(['php', 'artisan', 'e2e:reset-payroll', '--company=1', '--from=2026-04-01', '--to=2026-04-30']);
            $this->assertSame(1, $r->exitCode(), $label);
            $this->assertSame(1, $this->runs($file), "$label : nothing deleted");
        }

        $clone = $this->database("leezr_recipe_ok_{$suffix}", "leezr_recipe_ok_{$suffix}");
        $r = Process::env($this->childEnv($clone))->timeout(60)
            ->run(['php', 'artisan', 'e2e:reset-payroll', '--company=1', '--from=2026-04-01', '--to=2026-04-30']);
        $this->assertSame(0, $r->exitCode(), $r->output().$r->errorOutput());
        $this->assertSame(0, $this->runs($clone), 'the marked clone accepts the recipe command');
    }
}
