<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * Ensures every route whose controller belongs to a registered module
 * carries the appropriate module activation middleware.
 *
 * Admin modules: module.active:{key}
 * Company modules: company.access:use-module,{key}
 *
 * Infrastructure routes (auth, health, public) are exempt — their controllers
 * are outside App\Modules\ or inside App\Modules\Infrastructure\.
 */
class GlobalModuleRouteCoverageTest extends TestCase
{
    // ADR-149: modules whose routes are intentionally ungated (always accessible)
    private const UNGATED_MODULES = [
        'core.dashboard',
        'core.notifications',
        'platform.notifications',
    ];

    // W-SIGNATURE-1 (ADR-651): routes PUBLIQUES par conception (authentifiées par token opaque /
    // signature HMAC, AVANT auth Sanctum et contexte tenant) — un gate company.access y est impossible.
    // Allowlist étroite et explicite : toute AUTRE route de module reste exigée gatée.
    private const INTENTIONALLY_PUBLIC_URIS = [
        'api/webhooks/signatures/{provider}',
        'api/public/signature/{token}',
        'api/public/signature/{token}/document',
        'api/public/signature/{token}/prepare',
        'api/public/signature/{token}/sign',
        'api/public/signature/{token}/decline',
    ];

    public function test_all_module_routes_have_activation_middleware(): void
    {
        $allModules = ModuleRegistry::definitions();

        // Build module directory → (key, scope) mapping
        $modulePathToInfo = [];

        foreach ($allModules as $key => $manifest) {
            // Skip modules explicitly exempted from gating
            if (in_array($key, self::UNGATED_MODULES, true)) {
                continue;
            }

            $path = ModuleRegistry::modulePath($key);

            if ($path) {
                $modulePathToInfo[$path] = [
                    'key' => $key,
                    'scope' => $manifest->scope,
                ];
            }
        }

        $this->assertNotEmpty($modulePathToInfo, 'No modules found — registry may be broken.');

        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;

            if (!$controller || !is_string($controller)) {
                continue;
            }

            // Routes intentionnellement publiques (token/HMAC) — exemptées explicitement.
            if (in_array($route->uri(), self::INTENTIONALLY_PUBLIC_URIS, true)) {
                continue;
            }

            // Extract class name
            $className = str_contains($controller, '@')
                ? explode('@', $controller)[0]
                : $controller;

            if (!class_exists($className)) {
                continue;
            }

            // Only inspect controllers under App\Modules\ (skip Infrastructure)
            if (!str_starts_with($className, 'App\\Modules\\')) {
                continue;
            }

            // Skip infrastructure controllers (no module gate needed)
            if (str_starts_with($className, 'App\\Modules\\Infrastructure\\')) {
                continue;
            }

            // Resolve which module owns this controller
            $controllerFile = (new ReflectionClass($className))->getFileName();
            $matchedInfo = null;

            // Find the most specific (longest) matching module path
            $bestMatchLen = 0;

            foreach ($modulePathToInfo as $modulePath => $info) {
                if (str_starts_with($controllerFile, $modulePath) && strlen($modulePath) > $bestMatchLen) {
                    $matchedInfo = $info;
                    $bestMatchLen = strlen($modulePath);
                }
            }

            if (!$matchedInfo) {
                continue; // Controller not matched to any module
            }

            // W-HYGIENE-GOV : mapping par répertoire ambigu (modules partageant app/Modules/{Vertical}/)
            // → on exige UN gate d'activation de module VALIDE du bon scope (clé réelle du registre),
            // sans deviner la clé exacte. Une route sans aucun gate reste détectée.
            $middleware = $route->gatherMiddleware();
            $key = $matchedInfo['key'];
            $scope = $matchedInfo['scope'];
            $hasGate = false;

            foreach ($middleware as $mw) {
                if ($scope === 'admin' && preg_match('/^module\.active:(.+)$/', $mw, $m) && isset($allModules[$m[1]])) {
                    $hasGate = true;
                    break;
                }
                if ($scope === 'company' && preg_match('/^company\.access:use-module,(.+)$/', $mw, $m) && isset($allModules[$m[1]])) {
                    $hasGate = true;
                    break;
                }
            }

            if (!$hasGate) {
                $methods = implode('|', $route->methods());
                $expectedGate = $scope === 'admin'
                    ? "module.active:{$key}"
                    : "company.access:use-module,{$key}";
                $ungated[] = "{$methods} {$route->uri()} → expected: {$expectedGate}";
            }
        }

        $this->assertEmpty(
            $ungated,
            "Module routes missing activation middleware:\n" . implode("\n", $ungated),
        );
    }
}
