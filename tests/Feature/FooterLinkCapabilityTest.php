<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\FooterLinkBuilder;
use App\Modules\Core\Support\SupportModule;
use App\Modules\Platform\Support\PlatformSupportModule;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-353: Footer links are capability-driven.
 *
 * Modules declare footerLinks in their Capabilities.
 * FooterLinkBuilder collects from active modules, filters by permissions.
 * Nav endpoint returns footer_links alongside groups and header_widgets.
 * No hardcoded link logic in Footer.vue.
 */
class FooterLinkCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        CompanyPermissionCatalog::sync();
    }

    // ═══════════════════════════════════════════════════════
    // Capabilities VO
    // ═══════════════════════════════════════════════════════

    public function test_capabilities_includes_footer_links(): void
    {
        $caps = new Capabilities(
            footerLinks: [
                ['key' => 'test-link', 'label' => 'footer.test', 'to' => ['name' => 'test-route'], 'icon' => 'tabler-test', 'permission' => 'test.view', 'sortOrder' => 10],
            ],
        );

        $array = $caps->toArray();

        $this->assertArrayHasKey('footer_links', $array);
        $this->assertCount(1, $array['footer_links']);
        $this->assertSame('test-link', $array['footer_links'][0]['key']);
    }

    // ═══════════════════════════════════════════════════════
    // Module manifests
    // ═══════════════════════════════════════════════════════

    public function test_support_module_declares_footer_link(): void
    {
        $manifest = SupportModule::manifest();

        $this->assertNotEmpty(
            $manifest->capabilities->footerLinks,
            'SupportModule must declare footerLinks capability',
        );

        $link = $manifest->capabilities->footerLinks[0];
        $this->assertSame('footer-support', $link['key']);
        $this->assertSame('footer.support', $link['label']);
        $this->assertSame('support.view', $link['permission']);
        $this->assertSame(['name' => 'company-support'], $link['to']);
    }

    public function test_platform_support_module_declares_footer_link(): void
    {
        $manifest = PlatformSupportModule::manifest();

        $this->assertNotEmpty(
            $manifest->capabilities->footerLinks,
            'PlatformSupportModule must declare footerLinks capability',
        );

        $link = $manifest->capabilities->footerLinks[0];
        $this->assertSame('footer-support', $link['key']);
        $this->assertSame('footer.support', $link['label']);
        $this->assertSame('manage_support', $link['permission']);
        $this->assertSame(['name' => 'platform-support'], $link['to']);
    }

    // ═══════════════════════════════════════════════════════
    // Nav endpoint returns footer_links
    // ═══════════════════════════════════════════════════════

    public function test_company_nav_returns_footer_links(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();
        $response->assertJsonStructure(['groups', 'header_widgets', 'footer_links']);
        $this->assertIsArray($response->json('footer_links'));
    }

    public function test_platform_nav_returns_footer_links(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $response->assertOk();
        $response->assertJsonStructure(['groups', 'header_widgets', 'footer_links']);
        $this->assertIsArray($response->json('footer_links'));
    }

    // ═══════════════════════════════════════════════════════
    // Module activation controls footer link visibility
    // ═══════════════════════════════════════════════════════

    public function test_disabled_support_module_hides_footer_link(): void
    {
        \App\Core\Modules\PlatformModule::where('key', 'core.support')
            ->update(['is_enabled_globally' => false]);

        ModuleRegistry::clearCache();

        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $linkKeys = collect($response->json('footer_links'))->pluck('key')->all();

        $this->assertNotContains('footer-support', $linkKeys,
            'Disabled module footer links should not appear in footer_links');
    }

    // ═══════════════════════════════════════════════════════
    // Permission filtering
    // ═══════════════════════════════════════════════════════

    public function test_permission_filters_footer_link(): void
    {
        [$owner, $company] = $this->createCompanyOwner();

        // Create member without support.view permission
        $member = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $company->id,
            'key' => 'no_support',
            'name' => 'No Support',
            'is_administrative' => true,
        ]);

        // Grant some permission but NOT support.view
        $dashPerm = CompanyPermission::where('key', 'dashboard.view')->first();
        if ($dashPerm) {
            $role->permissions()->attach($dashPerm);
        }

        $company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actingAs($member)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $linkKeys = collect($response->json('footer_links'))->pluck('key')->all();

        $this->assertNotContains('footer-support', $linkKeys,
            'User without support.view should not see footer-support link');
    }

    public function test_owner_sees_all_footer_links(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $linkKeys = collect($response->json('footer_links'))->pluck('key')->all();

        $this->assertContains('footer-support', $linkKeys,
            'Owner (null permissions = bypass) should see all footer links');
    }

    // ═══════════════════════════════════════════════════════
    // Frontend invariant — no hardcoded links
    // ═══════════════════════════════════════════════════════

    public function test_footer_vue_no_hardcoded_links(): void
    {
        $content = file_get_contents(
            base_path('resources/js/layouts/components/Footer.vue'),
        );

        $this->assertStringNotContainsString(
            'company-support',
            $content,
            'Footer.vue must NOT hardcode route names (ADR-353: links come from nav store)',
        );

        $this->assertStringNotContainsString(
            'platform-support',
            $content,
            'Footer.vue must NOT hardcode route names (ADR-353: links come from nav store)',
        );

        $this->assertStringNotContainsString(
            'docs.leezr.com',
            $content,
            'Footer.vue must NOT hardcode external URLs (ADR-353: links come from module capabilities)',
        );

        $this->assertStringContainsString(
            'useNavStore',
            $content,
            'Footer.vue must import useNavStore for capability-driven footer link rendering',
        );
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function createSuperAdmin(): PlatformUser
    {
        $user = PlatformUser::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-footer@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $user->roles()->attach($superAdmin);

        return $user;
    }

    private function createCompanyOwner(): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Footer Co', 'slug' => 'footer-co-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $company];
    }
}
