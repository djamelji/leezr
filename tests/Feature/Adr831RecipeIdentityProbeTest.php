<?php

namespace Tests\Feature;

use App\Console\Recipe\RecipeDatabaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-831 — the served application proves, on its live connection, that it runs on a marked isolated clone ;
 * anything else answers 404 and reveals nothing. The E2E global setup refuses every target without this proof.
 */
class Adr831RecipeIdentityProbeTest extends TestCase
{
    use RefreshDatabase;

    private function bindIdentity(array $identity): void
    {
        $this->app->instance(RecipeDatabaseGuard::class, new class($identity) extends RecipeDatabaseGuard
        {
            public function __construct(private array $fixed) {}

            public function identify(?string $connection = null): array
            {
                return $this->fixed;
            }
        });
    }

    public function test_the_real_test_database_is_not_a_clone_and_reveals_nothing(): void
    {
        $this->getJson('/api/_recipe/identity')->assertNotFound()->assertExactJson(['clone' => false]);
    }

    public function test_a_marked_clone_names_itself(): void
    {
        $this->bindIdentity(['configured' => 'leezr_recipe_t', 'actual' => 'leezr_recipe_t', 'markers' => ['leezr_recipe_t']]);

        $this->getJson('/api/_recipe/identity')->assertOk()->assertExactJson(['clone' => true, 'database' => 'leezr_recipe_t']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function refusedIdentities(): array
    {
        return [
            'DEV database' => [['configured' => 'leezr', 'actual' => 'leezr', 'markers' => null]],
            'DEV database carrying a copied marker' => [['configured' => 'leezr', 'actual' => 'leezr', 'markers' => ['leezr']]],
            'clone name without marker' => [['configured' => 'leezr_recipe_t', 'actual' => 'leezr_recipe_t', 'markers' => null]],
            'marker naming another database' => [['configured' => 'leezr_recipe_t', 'actual' => 'leezr_recipe_t', 'markers' => ['leezr_recipe_x']]],
            'live database differs from configured' => [['configured' => 'leezr_recipe_t', 'actual' => 'leezr', 'markers' => ['leezr_recipe_t']]],
            'connection not identifiable' => [['configured' => 'leezr_recipe_t', 'actual' => null, 'markers' => null, 'error' => 'down']],
        ];
    }

    /** @dataProvider refusedIdentities */
    public function test_anything_but_a_marked_clone_answers_404(array $identity): void
    {
        $this->bindIdentity($identity);

        $this->getJson('/api/_recipe/identity')->assertNotFound()->assertExactJson(['clone' => false]);
    }

    public function test_production_never_confirms_even_a_marked_clone(): void
    {
        $this->bindIdentity(['configured' => 'leezr_recipe_t', 'actual' => 'leezr_recipe_t', 'markers' => ['leezr_recipe_t']]);
        $this->app['env'] = 'production';

        $this->getJson('/api/_recipe/identity')->assertNotFound();
    }

    public function test_the_route_is_not_registered_in_production(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertMatchesRegularExpression("/if \\(! app\\(\\)->isProduction\\(\\)\\) \\{\\s*Route::get\\('\\/_recipe\\/identity'/", $routes);
    }
}
