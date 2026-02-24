<?php

namespace Tests\Feature;

use App\Core\Modules\AdminModuleService;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates the unified activation engine for admin-scope modules.
 *
 * After ADR-113, admin modules use the same ModuleGate as company modules.
 * Admin modules only need PlatformModule.is_enabled_globally (no company context).
 */
class ModuleActivationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    /**
     * An admin module with is_enabled_globally=true is considered active.
     */
    public function test_admin_module_is_active_when_enabled_globally(): void
    {
        // Pick any admin-scope module
        $adminModules = ModuleRegistry::forScope('admin');
        $key = array_key_first($adminModules);

        PlatformModule::where('key', $key)->update(['is_enabled_globally' => true]);

        $this->assertTrue(
            ModuleGate::isActiveForScope($key),
            "{$key} should be active when enabled globally",
        );
    }

    /**
     * An admin module with is_enabled_globally=false is considered inactive.
     */
    public function test_admin_module_is_inactive_when_disabled_globally(): void
    {
        $adminModules = ModuleRegistry::forScope('admin');
        $key = array_key_first($adminModules);

        PlatformModule::where('key', $key)->update(['is_enabled_globally' => false]);

        $this->assertFalse(
            ModuleGate::isActiveForScope($key),
            "{$key} should be inactive when disabled globally",
        );
    }

    /**
     * Admin modules do not require a company context for activation check.
     */
    public function test_admin_module_does_not_need_company_context(): void
    {
        $adminModules = ModuleRegistry::forScope('admin');
        $key = array_key_first($adminModules);

        PlatformModule::where('key', $key)->update(['is_enabled_globally' => true]);

        // Passing null company should still return true for admin scope
        $this->assertTrue(
            ModuleGate::isActiveForScope($key, null),
            "{$key} should be active without company context",
        );
    }

    /**
     * Company modules require a company context — null company returns false.
     */
    public function test_company_module_requires_company_context(): void
    {
        $companyModules = ModuleRegistry::forScope('company');
        $key = array_key_first($companyModules);

        PlatformModule::where('key', $key)->update(['is_enabled_globally' => true]);

        $this->assertFalse(
            ModuleGate::isActiveForScope($key, null),
            "{$key} should require a company context",
        );
    }

    /**
     * Internal admin modules (type: 'internal') cannot be toggled off.
     */
    public function test_internal_admin_modules_cannot_be_disabled(): void
    {
        // Find an internal admin module
        $internalKey = null;

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            if ($manifest->type === 'internal') {
                $internalKey = $key;
                break;
            }
        }

        $this->assertNotNull($internalKey, 'Should have at least one internal admin module');

        $result = AdminModuleService::disable($internalKey);

        $this->assertFalse($result['success']);
        $this->assertEquals(422, $result['status']);
        $this->assertStringContainsString('Internal modules cannot be toggled', $result['data']['message']);
    }

    /**
     * Addon admin modules (e.g. payments.stripe) can be toggled on/off.
     */
    public function test_addon_admin_modules_can_be_toggled(): void
    {
        // Find an addon admin module
        $addonKey = null;

        foreach (ModuleRegistry::forScope('admin') as $key => $manifest) {
            if ($manifest->type === 'addon') {
                $addonKey = $key;
                break;
            }
        }

        if (!$addonKey) {
            $this->markTestSkipped('No addon admin modules to test');
        }

        // Ensure enabled first
        PlatformModule::where('key', $addonKey)->update(['is_enabled_globally' => true]);

        // Disable it
        $result = AdminModuleService::disable($addonKey);
        $this->assertTrue($result['success']);

        $module = PlatformModule::where('key', $addonKey)->first();
        $this->assertFalse($module->is_enabled_globally);

        // Re-enable it
        $result = AdminModuleService::enable($addonKey);
        $this->assertTrue($result['success']);

        $module->refresh();
        $this->assertTrue($module->is_enabled_globally);
    }
}
