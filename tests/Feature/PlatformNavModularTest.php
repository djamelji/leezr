<?php

namespace Tests\Feature;

use App\Core\Navigation\NavBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform navigation = ONE link per hub (post Phase-1 behaviour, ADR-598 LOT A taxonomy kept).
 * No internal hub tab is injected as a sidebar item; a restricted role sees only the hubs it can
 * access. Permissions stay server-side (RBAC untouched). Rolls back the ADR-598 LOT B/F sub-item
 * injection that caused internal tabs in the sidebar + multiple active highlight.
 */
class PlatformNavModularTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    /** @return array<string,array<string>> group key => item keys (incl. any children) */
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

    public function test_super_admin_sees_one_link_per_hub_with_no_children(): void
    {
        $t = $this->tree(null);
        $this->assertSame(['dashboard', 'platform-messaging'], $t['cockpit']);
        $this->assertSame(['platform-companies', 'communications'], $t['clients']);
        $this->assertSame(['platform-billing'], $t['finance']);
        $this->assertSame(['catalog', 'platform-access', 'organization-presets'], $t['governance']); // ADR-638 (W-E2)
        $this->assertSame(['settings'], $t['configuration']);
        $this->assertSame(['operations'], $t['system']);
    }

    public function test_no_internal_hub_tab_is_rendered_as_a_sidebar_item(): void
    {
        $all = collect($this->tree(null))->flatten()->all();
        // These are hub tabs / pages — they must NEVER appear as sidebar items (the ADR-599 defect).
        foreach ([
            'plans', 'jobdomains', 'markets', 'catalog-modules',
            'access-users', 'access-roles', 'settings-general', 'settings-international',
            'fin-overview', 'fin-subscriptions', 'fin-invoices', 'fin-payments', 'fin-reconciliation',
            'messaging-email', 'messaging-notifications', 'comms-support', 'comms-documentation',
            'ops-health', 'ops-alerts', 'ops-security', 'ops-ai', 'ops-automations', 'ops-realtime', 'ops-usage', 'ops-audit',
        ] as $internal) {
            $this->assertNotContains($internal, $all, "internal tab '{$internal}' must not be a sidebar item");
        }
    }

    public function test_finance_reader_sees_only_the_finance_hub(): void
    {
        $t = $this->tree(['view_billing']);
        $this->assertSame(['finance'], array_keys($t));
        $this->assertSame(['platform-billing'], $t['finance']);
    }

    public function test_restricted_role_sees_only_accessible_hubs(): void
    {
        // manage_modules → Governance shows ONLY the Catalogue hub (Access needs manage_platform_users).
        $t = $this->tree(['manage_modules']);
        $this->assertSame(['governance'], array_keys($t));
        $this->assertSame(['catalog'], $t['governance']);
    }

    public function test_hubs_are_gated_by_their_landing_permission(): void
    {
        // A role with only view_dashboard sees just the dashboard hub — no messaging/system/etc.
        $t = $this->tree(['view_dashboard']);
        $this->assertSame(['cockpit'], array_keys($t));
        $this->assertSame(['dashboard'], $t['cockpit']);

        // Operations hub hidden without view_system_health; messaging hidden without manage_email.
        $this->assertArrayNotHasKey('system', $t);
        $this->assertNotContains('platform-messaging', $t['cockpit']);
    }

    public function test_empty_groups_are_pruned(): void
    {
        $t = $this->tree(['view_billing']);
        $this->assertArrayNotHasKey('governance', $t);
        $this->assertArrayNotHasKey('system', $t);
        $this->assertArrayNotHasKey('configuration', $t);
    }
}
