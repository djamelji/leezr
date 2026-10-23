<?php

namespace Tests\Feature;

use App\Core\Navigation\NavBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-598 (Phase 2) — modular, permission-driven platform navigation.
 * Groups are shared, hubs expose children, each item carries an effective permission, and a
 * parent group is pruned when the user is authorized for none of its children.
 */
class PlatformNavModularTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    /** @return array<string,array<string>> group key => flattened item+child keys */
    private function tree(?array $permissions): array
    {
        $out = [];
        foreach (NavBuilder::forAdmin($permissions) as $group) {
            $keys = [];
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
            $out[$group['key']] = $keys;
        }

        return $out;
    }

    public function test_super_admin_sees_full_six_domain_taxonomy(): void
    {
        $t = $this->tree(null);
        foreach (['cockpit', 'clients', 'finance', 'governance', 'configuration', 'system'] as $g) {
            $this->assertArrayHasKey($g, $t, "group {$g} present for super admin");
        }
        $this->assertContains('plans', $t['governance']);
        $this->assertContains('jobdomains', $t['governance']);
        $this->assertContains('settings-international', $t['configuration']);
    }

    public function test_plans_only_role_sees_catalogue_with_only_plans(): void
    {
        $t = $this->tree(['manage_plans']);
        $this->assertArrayHasKey('governance', $t);
        $this->assertContains('catalog', $t['governance']); // parent present
        $this->assertContains('plans', $t['governance']);
        $this->assertNotContains('catalog-modules', $t['governance']);
        $this->assertNotContains('jobdomains', $t['governance']);
        // No finance / system / configuration groups for a plans-only role.
        $this->assertArrayNotHasKey('finance', $t);
        $this->assertArrayNotHasKey('system', $t);
    }

    public function test_finance_reader_sees_only_billing(): void
    {
        $t = $this->tree(['view_billing']);
        $this->assertSame(['finance'], array_keys($t));
        $this->assertSame(['platform-billing'], $t['finance']);
    }

    public function test_hub_items_require_their_landing_permission(): void
    {
        // Without the landing permissions, messaging/operations items are hidden.
        $t = $this->tree(['view_dashboard']);
        $this->assertArrayHasKey('cockpit', $t);
        $this->assertContains('dashboard', $t['cockpit']);
        $this->assertNotContains('platform-messaging', $t['cockpit'], 'messaging hidden without manage_email');
        $this->assertArrayNotHasKey('system', $t, 'operations hidden without view_system_health');

        // With the permissions, they appear.
        $t2 = $this->tree(['view_dashboard', 'manage_email', 'view_system_health']);
        $this->assertContains('platform-messaging', $t2['cockpit']);
        $this->assertContains('operations', $t2['system']);
    }

    public function test_empty_parent_group_is_pruned(): void
    {
        // A role with an unrelated permission sees no governance group at all (all children filtered).
        $t = $this->tree(['view_billing']);
        $this->assertArrayNotHasKey('governance', $t);
        $this->assertArrayNotHasKey('configuration', $t);
    }
}
