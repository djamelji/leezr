<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ensures every route controller lives under App\Modules\.
 *
 * After ADR-113, all controllers must be in:
 *   - App\Modules\{Platform|Core|Logistics|Payments}\*  (module controllers)
 *   - App\Modules\Infrastructure\*  (auth, public, system, webhooks)
 *
 * No controller from App\Company\, App\Platform\, App\Core\, or App\Http\
 * should be referenced by any route.
 */
class NoOrphanRouteTest extends TestCase
{
    public function test_all_route_controllers_are_under_modules_namespace(): void
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;

            if (!$controller || !is_string($controller)) {
                continue; // Closure routes are fine
            }

            // Extract class name (strip @method if present)
            $className = str_contains($controller, '@')
                ? explode('@', $controller)[0]
                : $controller;

            if (!class_exists($className)) {
                continue;
            }

            // Skip non-app classes (e.g., Laravel's internal controllers)
            if (!str_starts_with($className, 'App\\')) {
                continue;
            }

            // All app controllers must be under App\Modules\
            if (!str_starts_with($className, 'App\\Modules\\')) {
                $methods = implode('|', $route->methods());
                $violations[] = "{$methods} {$route->uri()} → {$className} is not under App\\Modules\\";
            }
        }

        $this->assertEmpty(
            $violations,
            "Route controllers outside App\\Modules\\:\n" . implode("\n", $violations),
        );
    }
}
