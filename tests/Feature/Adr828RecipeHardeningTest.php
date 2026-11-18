<?php

namespace Tests\Feature;

use App\Console\Recipe\RecipeDatabaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

/**
 * ADR-828 — no unguarded write path left for the E2E specs : `e2e:php` (replacing `tinker --execute`) refuses
 * every database that is not a marked clone, secondary connections included ; maintenance writes need a marked
 * clone or the explicit LIVE database name. Forbidden databases receive zero writes (real child processes).
 */
class Adr828RecipeHardeningTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** A sqlite database file with a `probe` table, optionally carrying a clone marker. */
    private function db(string $name, ?string $marker): string
    {
        $file = sys_get_temp_dir()."/{$name}.sqlite";
        @unlink($file);
        $this->files[] = $file;
        $pdo = new PDO("sqlite:$file");
        $pdo->exec('CREATE TABLE probe (v TEXT)');
        if ($marker !== null) {
            $pdo->exec('CREATE TABLE '.RecipeDatabaseGuard::MARKER_TABLE.' (database_name varchar(64) primary key, source_database varchar(64), source_dump_sha256 char(64), created_at datetime)');
            $pdo->exec('INSERT INTO '.RecipeDatabaseGuard::MARKER_TABLE." VALUES ('$marker', 'leezr', '', '2026-11-18')");
        }

        return $file;
    }

    private function rows(string $file): int
    {
        return (int) (new PDO("sqlite:$file"))->query('SELECT COUNT(*) FROM probe')->fetchColumn();
    }

    private function script(string $php): string
    {
        $file = sys_get_temp_dir().'/adr828-'.uniqid().'.php';
        file_put_contents($file, "<?php\n".$php);
        $this->files[] = $file;

        return $file;
    }

    private function child(string $database, string $script, string $env = 'local')
    {
        return Process::env(['APP_ENV' => $env, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DB_FOREIGN_KEYS' => 'false'])
            ->timeout(60)->run(['php', 'artisan', 'e2e:php', $script]);
    }

    public function test_e2e_php_refuses_every_unmarked_database_with_zero_write(): void
    {
        $s = substr(md5(uniqid('', true)), 0, 8);
        $write = $this->script("DB::table('probe')->insert(['v' => 'written']); echo 'WROTE';");
        $cases = [
            'DEV-like' => [$this->db("leezr_devlike_{$s}", null), 'local'],
            'production-like name, marked' => [$this->db("c1leezr_{$s}", "c1leezr_{$s}"), 'local'],
            'unknown, marked' => [$this->db("other_{$s}", "other_{$s}"), 'local'],
            'clone name, no marker' => [$this->db("leezr_recipe_nm_{$s}", null), 'local'],
            'clone name, foreign marker' => [$this->db("leezr_recipe_fm_{$s}", 'leezr_recipe_elsewhere'), 'local'],
            'marked clone, APP_ENV production' => [$this->db("leezr_recipe_pr_{$s}", "leezr_recipe_pr_{$s}"), 'production'],
            'APP_ENV testing, unmarked' => [$this->db("leezr_recipe_ts_{$s}", null), 'testing'],
        ];
        foreach ($cases as $label => [$file, $env]) {
            $r = $this->child($file, $write, $env);
            $this->assertSame(1, $r->exitCode(), $label);
            $this->assertStringNotContainsString('WROTE', $r->output(), $label);
            $this->assertSame(0, $this->rows($file), "$label : zero write");
        }

        $clone = $this->db("leezr_recipe_ok_{$s}", "leezr_recipe_ok_{$s}");
        $r = $this->child($clone, $write);
        $this->assertSame(0, $r->exitCode(), $r->output().$r->errorOutput());
        $this->assertStringContainsString('WROTE', $r->output());
        $this->assertSame(1, $this->rows($clone), 'the marked clone accepts the fixture script');

        $failing = $this->script("DB::table('probe')->insert(['v' => 'x']); throw new RuntimeException('fixture failed');");
        $this->assertNotSame(0, $this->child($clone, $failing)->exitCode());
        $this->assertSame(1, $this->rows($clone), 'a failing fixture script is rolled back');
    }

    public function test_a_secondary_connection_must_be_a_marked_clone_too(): void
    {
        $s = substr(md5(uniqid('', true)), 0, 8);
        $clone = $this->db("leezr_recipe_main_{$s}", "leezr_recipe_main_{$s}");
        $dev = $this->db("leezr_dev_side_{$s}", null);
        $other = $this->db("leezr_recipe_side_{$s}", "leezr_recipe_side_{$s}");
        $side = fn (string $target) => $this->script(
            "DB::table('probe')->insert(['v' => 'main']);\n".
            "config(['database.connections.side' => ['driver' => 'sqlite', 'database' => '{$target}', 'prefix' => '', 'foreign_key_constraints' => false]]);\n".
            "DB::connection('side')->table('probe')->insert(['v' => 'side']); echo 'WROTE';"
        );

        $r = $this->child($clone, $side($dev));
        $this->assertNotSame(0, $r->exitCode());
        $this->assertStringContainsString('secondary connection side refused', $r->output().$r->errorOutput());
        $this->assertSame(0, $this->rows($dev), 'the unmarked secondary database receives nothing');
        $this->assertSame(0, $this->rows($clone), 'the main write is rolled back too');

        $r = $this->child($clone, $side($other));
        $this->assertSame(0, $r->exitCode(), $r->output().$r->errorOutput());
        $this->assertSame(1, $this->rows($other), 'a marked secondary clone is accepted');
    }

    public function test_maintenance_writes_need_a_marked_clone_or_the_live_database_name(): void
    {
        $s = substr(md5(uniqid('', true)), 0, 8);
        $guard = app(RecipeDatabaseGuard::class);
        $probe = function (string $file) {
            config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]]);
            \Illuminate\Support\Facades\DB::purge('probe'); // the guard reads the LIVE connection, not the config
        };

        $probe($this->db("leezr_recipe_mt_{$s}", "leezr_recipe_mt_{$s}"));
        $v = $guard->authorizeMaintenance('t', null, 'probe');
        $this->assertSame('marked_clone', $v['mode'], json_encode($v));

        $probe($this->db("leezr_devlike_mt_{$s}", null));
        $this->assertFalse($guard->authorizeMaintenance('t', null, 'probe')['allowed'], 'no confirmation');
        $this->assertFalse($guard->authorizeMaintenance('t', 'leezr', 'probe')['allowed'], 'confirmation of another database');
        $this->assertSame('explicit_confirmation', $guard->authorizeMaintenance('t', "leezr_devlike_mt_{$s}", 'probe')['mode']);

        $this->app['env'] = 'production';
        $this->assertFalse($guard->authorizeMaintenance('t', "leezr_devlike_mt_{$s}", 'probe')['allowed'], 'never in production');
        $this->app['env'] = 'testing';

        $this->assertFalse($guard->authorizeMaintenance('t', ':memory:')['allowed'], 'the in-memory test database is not identifiable');
    }

    public function test_maintenance_and_recipe_modes_of_the_audited_commands_are_refused_here(): void
    {
        $this->assertSame(1, Artisan::call('workforce:planning-loadtest', ['action' => 'seed', '--employees' => 1]));
        $this->assertSame(1, Artisan::call('workforce:planning-loadtest', ['action' => 'purge', '--employees' => 1]));
        $this->assertSame(0, \App\Core\Models\Company::count(), 'nothing seeded');
        $this->assertSame(1, Artisan::call('onboarding:scan-uploads', ['--simulate' => true]));
        $this->assertStringContainsString('RECIPE GUARD', Artisan::output());
    }
}
