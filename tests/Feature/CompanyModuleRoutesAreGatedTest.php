<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * Ensures every company route whose controller belongs to a company-scope
 * module is gated by company.access:use-module,{moduleKey}.
 *
 * If a new module adds routes without the gate, this test fails.
 */
class CompanyModuleRoutesAreGatedTest extends TestCase
{
    // ADR-149: modules whose routes are intentionally ungated (always accessible)
    private const UNGATED_MODULES = [
        'core.dashboard',
        'core.notifications',
    ];

    public function test_all_company_module_routes_have_module_gate(): void
    {
        $companyModules = ModuleRegistry::forScope('company');

        // Build module directory → key mapping
        $modulePathToKey = [];

        foreach ($companyModules as $key => $manifest) {
            // Skip modules explicitly exempted from gating
            if (in_array($key, self::UNGATED_MODULES, true)) {
                continue;
            }

            $path = ModuleRegistry::modulePath($key);

            if ($path) {
                $modulePathToKey[$path] = $key;
            }
        }

        $this->assertNotEmpty($modulePathToKey, 'No company modules found — registry may be broken.');

        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;

            if (!$controller || !is_string($controller)) {
                continue;
            }

            // Extract class name (strip @method if present)
            $className = str_contains($controller, '@')
                ? explode('@', $controller)[0]
                : $controller;

            if (!class_exists($className)) {
                continue;
            }

            // Only inspect controllers under App\Modules\
            if (!str_starts_with($className, 'App\\Modules\\')) {
                continue;
            }

            // Resolve which company module owns this controller
            $controllerFile = (new ReflectionClass($className))->getFileName();
            $matchedKey = null;

            foreach ($modulePathToKey as $modulePath => $key) {
                if (str_starts_with($controllerFile, $modulePath)) {
                    $matchedKey = $key;

                    break;
                }
            }

            // Controller not in a company-scope module — skip
            if (!$matchedKey) {
                continue;
            }

            // Verify the route carries the module gate middleware
            $middleware = $route->gatherMiddleware();
            $expectedGate = "company.access:use-module,{$matchedKey}";
            $hasGate = in_array($expectedGate, $middleware, true);

            if (!$hasGate) {
                $methods = implode('|', $route->methods());
                $ungated[] = "{$methods} {$route->uri()} → expected gate: {$expectedGate}";
            }
        }

        $this->assertEmpty(
            $ungated,
            "Company module routes missing module gate middleware:\n" . implode("\n", $ungated),
        );
    }

    public function test_every_company_module_with_capabilities_has_gated_routes(): void
    {
        $companyModules = ModuleRegistry::forScope('company');
        $missing = [];

        foreach ($companyModules as $key => $manifest) {
            $routeNames = $manifest->capabilities->routeNames;

            if (empty($routeNames)) {
                continue; // Stub module — no routes to gate
            }

            $path = ModuleRegistry::modulePath($key);

            if (!$path) {
                $missing[] = "{$key}: no module path resolved";

                continue;
            }

            // Verify at least one route exists for this module
            $found = false;

            foreach (Route::getRoutes() as $route) {
                $action = $route->getAction();
                $controller = $action['controller'] ?? null;

                if (!$controller || !is_string($controller)) {
                    continue;
                }

                $className = str_contains($controller, '@')
                    ? explode('@', $controller)[0]
                    : $controller;

                if (!class_exists($className)) {
                    continue;
                }

                $controllerFile = (new ReflectionClass($className))->getFileName();

                if (str_starts_with($controllerFile, $path)) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                $missing[] = "{$key}: declares capabilities but has no registered routes";
            }
        }

        $this->assertEmpty(
            $missing,
            "Company modules with capabilities but no registered routes:\n" . implode("\n", $missing),
        );
    }
}
