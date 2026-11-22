<?php

namespace Tests\Feature;

use App\Core\Documents\Scanning\FileScannerManager;
use App\Core\Documents\Scanning\SimulatedScanner;
use App\Core\Documents\Scanning\UnavailableScanner;
use Tests\TestCase;

/**
 * ADR-862 (Leezr 5.0 L7 closure) — the hiring recipe needs the simulated antivirus ; it lives in the recipe server only
 * and production can never use it, whatever its configuration says.
 */
class Adr862RecipeEnvironmentTest extends TestCase
{
    public function test_the_simulated_antivirus_is_refused_in_production_even_when_configured(): void
    {
        config(['documents.scanner.driver' => 'simulated']);
        $this->app['env'] = 'production';
        try {
            $this->assertInstanceOf(UnavailableScanner::class, FileScannerManager::driver());
            $this->assertInstanceOf(UnavailableScanner::class, FileScannerManager::driver('simulated')); // the --simulate override too
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertInstanceOf(SimulatedScanner::class, FileScannerManager::driver()); // outside production : the test driver
    }

    public function test_the_simulated_antivirus_is_set_only_by_the_clone_guarded_recipe_server(): void
    {
        $serve = file_get_contents(base_path('scripts/audit842-serve.sh'));
        $this->assertStringContainsString('DOCUMENT_SCANNER=simulated', $serve);
        $this->assertStringContainsString('leezr_audit*', $serve);           // refuses any database but a recipe clone
        $this->assertStringContainsString('_recipe_clone_identity', $serve); // … and an unmarked clone
        foreach (['.env.example', '.env.production.example'] as $env) {
            if (is_file(base_path($env))) {
                $this->assertDoesNotMatchRegularExpression('/^DOCUMENT_SCANNER\s*=\s*simulated/m', file_get_contents(base_path($env)), $env);
            }
        }
    }
}
