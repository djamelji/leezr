<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates that every module declares a valid scope.
 *
 * After ADR-113, scope is mandatory ('admin' | 'company').
 * The legacy value 'platform' must not appear.
 */
class ModuleScopeDeclarationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    /**
     * Every module must declare a non-empty scope.
     * Since the default was removed from ModuleManifest, PHP will throw
     * a TypeError if scope is omitted — this test guards against empty strings.
     */
    public function test_every_module_declares_scope_explicitly(): void
    {
        $modules = ModuleRegistry::definitions();
        $this->assertNotEmpty($modules);

        foreach ($modules as $key => $manifest) {
            $this->assertNotEmpty($manifest->scope, "{$key} has an empty scope");
        }
    }

    /**
     * Only 'admin' and 'company' are valid scope values.
     */
    public function test_all_scopes_are_admin_or_company(): void
    {
        $violations = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if (!in_array($manifest->scope, ['admin', 'company'], true)) {
                $violations[] = "{$key}: scope '{$manifest->scope}' is not admin or company";
            }
        }

        $this->assertEmpty(
            $violations,
            "Invalid scopes:\n" . implode("\n", $violations),
        );
    }

    /**
     * The legacy 'platform' scope value must not appear.
     */
    public function test_no_module_uses_legacy_platform_scope(): void
    {
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            $this->assertNotEquals(
                'platform',
                $manifest->scope,
                "{$key} still uses legacy scope 'platform'. Change to 'admin'.",
            );
        }
    }

    /**
     * Admin-scope modules must use the 'platform.' or 'payments.' key prefix.
     */
    public function test_admin_scope_modules_keep_platform_key_prefix(): void
    {
        $violations = [];

        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->scope === 'admin') {
                if (!str_starts_with($key, 'platform.') && !str_starts_with($key, 'payments.')) {
                    $violations[] = "{$key}: admin-scope module should have 'platform.' or 'payments.' key prefix";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Admin module key prefix violations:\n" . implode("\n", $violations),
        );
    }
}
